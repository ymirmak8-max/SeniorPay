<?php
require_once __DIR__ . '/../connections.php';
require_once __DIR__ . '/../Classes/Participant.php';

$participantClass = new Participant($conn);
$redirectUrl = '../index.php';

function redirectWithMessage($url, $status, $message) {
    $location = $url . '?status=' . urlencode($status) . '&message=' . urlencode($message);
    header('Location: ' . $location);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage($redirectUrl, 'info', 'No request was submitted.');
}

if (isset($_POST['addParticipants'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $Email = trim($_POST['Email'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $city = trim($_POST['city'] ?? '');

    if ($first_name === '' || $last_name === '' || $Email === '' || $gender === '' || $birth_date === '' || $city === '') {
        redirectWithMessage($redirectUrl, 'error', 'Please fill in all required fields.');
    }

    if ($participantClass->addParticipants($first_name, $last_name, $Email, $gender, $birth_date, $city)) {
        redirectWithMessage($redirectUrl, 'success', 'Participant added successfully.');
    }

    redirectWithMessage($redirectUrl, 'error', 'Failed to add participant to the database.');
}

if (isset($_POST['updateParticipants'])) {
    $participant_id = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $Email = trim($_POST['Email'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $city = trim($_POST['city'] ?? '');

    if (!$participant_id || $first_name === '' || $last_name === '' || $Email === '' || $gender === '' || $birth_date === '' || $city === '') {
        redirectWithMessage($redirectUrl, 'error', 'Invalid participant details or missing ID.');
    }

    if ($participantClass->updateParticipants($participant_id, $first_name, $last_name, $Email, $gender, $birth_date, $city)) {
        redirectWithMessage($redirectUrl, 'success', 'Participant updated successfully.');
    }

    redirectWithMessage($redirectUrl, 'error', 'Failed to update participant in the database.');
}

if (isset($_POST['deleteParticipants'])) {
    $participant_id = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);

    if (!$participant_id) {
        redirectWithMessage($redirectUrl, 'error', 'Invalid participant ID to delete.');
    }

    if ($participantClass->deleteParticipants($participant_id)) {
        redirectWithMessage($redirectUrl, 'success', 'Participant deleted successfully.');
    }

    redirectWithMessage($redirectUrl, 'error', 'Failed to delete participant from the database.');
}

redirectWithMessage($redirectUrl, 'info', 'No valid action was requested.');
?>