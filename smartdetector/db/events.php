<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => '\plagiarism_smartdetector\observer::submission_submitted',
    ],
    [
        'eventname' => '\mod_assign\event\submission_created',
        'callback'  => '\plagiarism_smartdetector\observer::submission_submitted',
    ],
    [
        'eventname' => '\mod_assign\event\submission_updated',
        'callback'  => '\plagiarism_smartdetector\observer::submission_submitted',
    ],
];