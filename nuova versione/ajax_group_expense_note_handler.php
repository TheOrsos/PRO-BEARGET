<?php
session_start();
require_once 'db_connect.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accesso non autorizzato.']);
    exit;
}

$user_id = $_SESSION['id'];
$response = ['success' => false, 'message' => 'Azione non valida.'];

// Helper function to check if the user is a member of the fund associated with the expense
function verify_group_expense_member($conn, $expense_id, $user_id) {
    $sql = "SELECT ge.id FROM group_expenses ge
            JOIN shared_fund_members sfm ON ge.fund_id = sfm.fund_id
            WHERE ge.id = ? AND sfm.user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $expense_id, $user_id);
    $stmt->execute();
    $stmt->store_result();
    $is_member = $stmt->num_rows > 0;
    $stmt->close();
    return $is_member;
}

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_note' && isset($_POST['expense_id'], $_POST['note_content'])) {
        $expense_id = intval($_POST['expense_id']);
        $note_content = trim($_POST['note_content']);

        if (!verify_group_expense_member($conn, $expense_id, $user_id)) {
            $response['message'] = 'Spesa non trovata o non autorizzata.';
        } else {
            $sql_get_note_id = "SELECT note_id FROM group_expenses WHERE id = ?";
            $stmt_get = $conn->prepare($sql_get_note_id);
            $stmt_get->bind_param("i", $expense_id);
            $stmt_get->execute();
            $result = $stmt_get->get_result();
            $expense_data = $result->fetch_assoc();
            $note_id = $expense_data ? $expense_data['note_id'] : null;
            $stmt_get->close();

            $conn->begin_transaction();
            try {
                if ($note_id) { // Note exists
                    if (empty($note_content)) {
                        // Delete note and nullify in group_expenses
                        $sql_delete = "DELETE FROM notes WHERE id = ?";
                        $stmt_delete = $conn->prepare($sql_delete);
                        $stmt_delete->bind_param("i", $note_id);
                        $stmt_delete->execute();
                        $stmt_delete->close();

                        $sql_update_expense = "UPDATE group_expenses SET note_id = NULL WHERE id = ?";
                        $stmt_update_expense = $conn->prepare($sql_update_expense);
                        $stmt_update_expense->bind_param("i", $expense_id);
                        $stmt_update_expense->execute();
                        $stmt_update_expense->close();

                        $response = ['success' => true, 'message' => 'Nota eliminata.'];
                    } else {
                        // Update note
                        $sql_update = "UPDATE notes SET content = ? WHERE id = ?";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->bind_param("si", $note_content, $note_id);
                        $stmt_update->execute();
                        $stmt_update->close();
                        $response = ['success' => true, 'message' => 'Nota aggiornata.'];
                    }
                } else { // Note does not exist
                    if (!empty($note_content)) {
                        // Create note and link it
                        $title = "Nota per spesa di gruppo #" . $expense_id;
                        $sql_insert = "INSERT INTO notes (user_id, title, content, creator_id) VALUES (?, ?, ?, ?)";
                        $stmt_insert = $conn->prepare($sql_insert);
                        $stmt_insert->bind_param("issi", $user_id, $title, $note_content, $user_id);
                        $stmt_insert->execute();
                        $new_note_id = $stmt_insert->insert_id;
                        $stmt_insert->close();

                        $sql_update_expense = "UPDATE group_expenses SET note_id = ? WHERE id = ?";
                        $stmt_update_expense = $conn->prepare($sql_update_expense);
                        $stmt_update_expense->bind_param("ii", $new_note_id, $expense_id);
                        $stmt_update_expense->execute();
                        $stmt_update_expense->close();

                        $response = ['success' => true, 'message' => 'Nota creata.'];
                    } else {
                        $response = ['success' => true, 'message' => 'Nessuna azione richiesta.'];
                    }
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = 'Errore durante il salvataggio della nota: ' . $e->getMessage();
            }
        }
    }
} elseif (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'get_note' && isset($_GET['expense_id'])) {
        $expense_id = intval($_GET['expense_id']);

        if (!verify_group_expense_member($conn, $expense_id, $user_id)) {
            $response['message'] = 'Spesa non trovata o non autorizzata.';
        } else {
            $sql_get = "SELECT n.content FROM notes n JOIN group_expenses ge ON n.id = ge.note_id WHERE ge.id = ?";
            $stmt_get = $conn->prepare($sql_get);
            $stmt_get->bind_param("i", $expense_id);
            $stmt_get->execute();
            $result_get = $stmt_get->get_result();
            if ($note = $result_get->fetch_assoc()) {
                $response = ['success' => true, 'content' => $note['content']];
            } else {
                $response = ['success' => true, 'content' => '']; // No note found
            }
            $stmt_get->close();
        }
    }
}

echo json_encode($response);
$conn->close();
?>
