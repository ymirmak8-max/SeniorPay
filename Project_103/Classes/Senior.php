<?php

class Participant {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function getAllParticipants() {
        $query = "SELECT * FROM participants ORDER BY participant_id DESC";
        $stmt = $this->conn->query($query);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function addParticipants($first_name, $last_name, $Email, $gender, $birth_date, $city) {
        $query = "INSERT INTO participants (first_name, last_name, Email, gender, birth_date, city) 
                  VALUES (:first_name, :last_name, :Email, :gender, :birth_date, :city)";
        $stmt = $this->conn->prepare($query);

        return $stmt->execute([
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':Email' => $Email,
            ':gender' => $gender,
            ':birth_date' => $birth_date,
            ':city' => $city,
        ]);
    }

    public function updateParticipants($participant_id, $first_name, $last_name, $Email, $gender, $birth_date, $city) {
        $query = "UPDATE participants 
                  SET first_name = :first_name,
                      last_name = :last_name,
                      Email = :Email,
                      gender = :gender,
                      birth_date = :birth_date,
                      city = :city
                  WHERE participant_id = :participant_id";
        $stmt = $this->conn->prepare($query);

        return $stmt->execute([
            ':participant_id' => $participant_id,
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':Email' => $Email,
            ':gender' => $gender,
            ':birth_date' => $birth_date,
            ':city' => $city,
        ]);
    }

    public function deleteParticipants($participant_id) {
        $query = "DELETE FROM participants WHERE participant_id = :participant_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':participant_id' => $participant_id]);
    }
}