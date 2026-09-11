import os
import re
import io
import zipfile
from typing import Optional, List
from fastapi import FastAPI, Header, HTTPException, Depends, Request
from pydantic import BaseModel
from transformers import pipeline
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

app = FastAPI(
    title="SmartDetector Self-Hosted API",
    description="Microservice Mandiri untuk Deteksi AI dan Plagiarisme",
    version="2.0.0"
)

# Configuration
API_TOKEN = "8HzYwd9ifX7SRiAR5gZXw2IUsMeZWHoaKsSako8rb20a3e69"

# Load AI Detection Model (RoBERTa / DeBERTa based AI detector)
# Model dimuat sekali saat server start agar eksekusi cepat
print("Loading AI Detection Model...")
try:
    ai_classifier = pipeline(
        "text-classification",
        model="roberta-base-openai-detector",
        return_all_scores=True
    )
    print("AI Model loaded successfully.")
except Exception as e:
    print(f"Warning: Failed to load online model ({e}). Using fallback dummy logic.")
    ai_classifier = None


# Schema Request & Response
class ScanRequest(BaseModel):
    text: str
    language: Optional[str] = "auto"
    existing_documents: Optional[List[str]] = []  # Kumpulan teks tugas lain untuk pembanding plagiarisme


def verify_token(authorization: Optional[str] = Header(None)):
    if not authorization:
        raise HTTPException(status_code=401, detail="Authorization header missing")
    
    token = authorization.replace("Bearer ", "").strip()
    if token != API_TOKEN:
        raise HTTPException(status_code=403, detail="Invalid API Key")
    return True


@app.get("/")
def root():
    return {"status": "online", "message": "SmartDetector API is running"}


def extract_pdf_text(content: bytes) -> tuple[str, str]:
    """Extract text from a PDF, falling back to OCR for scanned pages."""
    try:
        import fitz

        document = fitz.open(stream=content, filetype="pdf")
        text = "\n".join(page.get_text() for page in document).strip()
        if text:
            return text, "text-layer"

        try:
            import pytesseract
            from PIL import Image
        except ImportError:
            return "", "ocr-unavailable"

        ocr_pages = []
        for page in document:
            pixmap = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
            image = Image.open(io.BytesIO(pixmap.tobytes("png")))
            ocr_pages.append(pytesseract.image_to_string(image))
        text = "\n".join(ocr_pages).strip()
        return text, "ocr" if text else "empty"
    except Exception as error:
        raise HTTPException(status_code=422, detail=f"PDF extraction failed: {error}") from error


def extract_document(content: bytes, filename: str, content_type: Optional[str]) -> tuple[str, str]:
    if content_type == "application/pdf" or filename.lower().endswith(".pdf"):
        return extract_pdf_text(content)

    if content_type == "application/vnd.openxmlformats-officedocument.wordprocessingml.document" or filename.lower().endswith(".docx"):
        try:
            with zipfile.ZipFile(io.BytesIO(content)) as archive:
                xml = archive.read("word/document.xml").decode("utf-8")
            text = re.sub(r"</w:p>|</w:tr>", "\n", xml)
            text = re.sub(r"<[^>]+>", "", text)
            return text.strip(), "docx"
        except Exception as error:
            raise HTTPException(status_code=422, detail=f"DOCX extraction failed: {error}") from error

    try:
        return content.decode("utf-8", errors="replace").strip(), "plain-text"
    except Exception as error:
        raise HTTPException(status_code=422, detail=f"Text extraction failed: {error}") from error


@app.post("/v2/extract", dependencies=[Depends(verify_token)])
async def extract_file(
    request: Request,
    filename: str = "document",
    content_type: Optional[str] = Header(None),
):
    content = await request.body()
    text, method = extract_document(content, filename, content_type)
    return {
        "status": 200 if text else 422,
        "text": text,
        "method": method,
        "textWordCounts": len(text.split()),
    }


# Endpoint 1: Plagiarism Detection
@app.post("/v2/plagiarism", dependencies=[Depends(verify_token)])
def detect_plagiarism(req: ScanRequest):
    text = req.text.strip()
    word_count = len(text.split())

    if word_count == 0:
        return {
            "status": 400,
            "result": {"score": 0, "textWordCounts": 0}
        }

    # Jika ada dokumen pembanding yang dikirim oleh Moodle
    max_similarity = 0.0
    if req.existing_documents and len(req.existing_documents) > 0:
        corpus = [text] + req.existing_documents
        vectorizer = TfidfVectorizer().fit_transform(corpus)
        vectors = vectorizer.toarray()
        
        # Hitung kemiripan teks baru (index 0) dengan semua teks lama
        cosine_matrix = cosine_similarity([vectors[0]], vectors[1:])
        if cosine_matrix.size > 0:
            max_similarity = float(cosine_matrix.max()) * 100

    return {
        "status": 200,
        "scanInformation": {
            "service": "local-plagiarism-detector",
            "inputType": "text"
        },
        "result": {
            "score": round(max_similarity, 2),
            "textWordCounts": word_count,
            "totalPlagiarismWords": int((max_similarity / 100) * word_count)
        }
    }


# Endpoint 2: AI Content Detection
@app.post("/v2/ai-content-detection", dependencies=[Depends(verify_token)])
def detect_ai(req: ScanRequest):
    global ai_classifier

    text = req.text.strip()
    word_count = len(text.split())

    if word_count == 0:
        return {"status": 400, "score": 0}

    ai_score = 0.0
    model_failed = False

    if ai_classifier:
        # Jalankan prediksi dengan HuggingFace Model
        # Potong teks maksimal 512 token untuk keamanan memori
        truncated_text = text[:1500] 
        try:
            predictions = ai_classifier(truncated_text)
            if predictions and isinstance(predictions[0], list):
                predictions = predictions[0]

            # Cari skor label "Fake" / "AI Generated"
            for pred in predictions:
                if pred['label'].upper() in ['FAKE', 'LABEL_1', 'AI']:
                    ai_score = pred['score'] * 100
                    break
        except Exception as error:
            print(f"Warning: AI model prediction failed ({error}). Using fallback heuristic.")
            model_failed = True

    if not ai_classifier or model_failed:
        # Heuristic fallback jika model AI belum diunduh
        # Memeriksa pola variasi kata dan kerapihan struktur kalimat
        sentences = re.split(r'[.!?]+', text)
        sentence_lengths = [len(s.split()) for s in sentences if s.strip()]
        
        if len(sentence_lengths) > 1:
            avg_len = sum(sentence_lengths) / len(sentence_lengths)
            variance = sum((x - avg_len) ** 2 for x in sentence_lengths) / len(sentence_lengths)
            # Teks AI cenderung memiliki variasi panjang kalimat yang sangat konsisten (variansi rendah)
            if variance < 10:
                ai_score = 75.0
            else:
                ai_score = 25.0
        else:
            ai_score = 10.0

    return {
        "status": 200,
        "score": round(ai_score, 2),
        "readability_score": 80,
        "language": req.language
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="127.0.0.1", port=8000, reload=True)