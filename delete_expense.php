<?php
session_start();
header('Content-Type: application/json');

require_once 'db_connect.php';
require_once 'functions.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accesso non autorizzato.']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION['id'];
    $expense_id = $_POST['expense_id'] ?? 0;
    $fund_id = $_POST['fund_id'] ?? 0;

    if (empty($expense_id) || empty($fund_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID spesa o fondo mancante.']);
        exit();
    }

    // --- Permission Check: Verify user is a member of the fund ---
    $members = get_fund_members($conn, $fund_id);
    $is_member = false;
    foreach ($members as $member) {
        if ($member['id'] == $user_id) {
            $is_member = true;
            break;
        }
    }
    if (!$is_member) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Non sei un membro di questo fondo.']);
        exit;
    }

    $conn->begin_transaction();

    try {
        // --- Get Expense Details ---
        // ASSUMPTION: The 'group_expenses' table has a 'transaction_id' and a 'note_id' column
        // that links to the corresponding records to be deleted.
        $sql_get_expense = "SELECT transaction_id, note_id, attachment_path FROM group_expenses WHERE id = ?";
        $stmt_get = $conn->prepare($sql_get_expense);
        $stmt_get->bind_param("i", $expense_id);
        $stmt_get->execute();
        $expense = $stmt_get->get_result()->fetch_assoc();
        $stmt_get->close();

        if (!$expense) {
            throw new Exception("Spesa non trovata.");
        }

        // --- 1. Delete Main Transaction (restores balance implicitly) ---
        if (!empty($expense['transaction_id'])) {
            $sql_delete_tx = "DELETE FROM transactions WHERE id = ? AND user_id = (SELECT paid_by_user_id FROM group_expenses WHERE id = ?)";
            $stmt_delete_tx = $conn->prepare($sql_delete_tx);
            $stmt_delete_tx->bind_param("ii", $expense['transaction_id'], $expense_id);
            $stmt_delete_tx->execute();
            $stmt_delete_tx->close();
        }

        // --- 2. Delete Expense Splits ---
        $sql_delete_splits = "DELETE FROM expense_splits WHERE expense_id = ?";
        $stmt_delete_splits = $conn->prepare($sql_delete_splits);
        $stmt_delete_splits->bind_param("i", $expense_id);
        $stmt_delete_splits->execute();
        $stmt_delete_splits->close();

        // --- 3. Delete Attachment File ---
        if (!empty($expense['attachment_path']) && file_exists($expense['attachment_path'])) {
            unlink($expense['attachment_path']);
        }

        // --- 4. Delete Note and Shares ---
        if (!empty($expense['note_id'])) {
            $note_id = $expense['note_id'];
            $sql_delete_note_shares = "DELETE FROM note_shares WHERE note_id = ?";
            $stmt_delete_note_shares = $conn->prepare($sql_delete_note_shares);
            $stmt_delete_note_shares->bind_param("i", $note_id);
            $stmt_delete_note_shares->execute();
            $stmt_delete_note_shares->close();

            $sql_delete_note = "DELETE FROM notes WHERE id = ?";
            $stmt_delete_note = $conn->prepare($sql_delete_note);
            $stmt_delete_note->bind_param("i", $note_id);
            $stmt_delete_note->execute();
            $stmt_delete_note->close();
        }

        // --- 5. Delete Group Expense Record ---
        $sql_delete_expense = "DELETE FROM group_expenses WHERE id = ?";
        $stmt_delete_expense = $conn->prepare($sql_delete_expense);
        $stmt_delete_expense->bind_param("i", $expense_id);
        $stmt_delete_expense->execute();

        if ($stmt_delete_expense->affected_rows > 0) {
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Spesa di gruppo eliminata con successo!']);
        } else {
            throw new Exception("Nessuna spesa eliminata. Potrebbe essere già stata rimossa.");
        }
        $stmt_delete_expense->close();

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante l\'eliminazione della spesa: ' . $e->getMessage()]);
    }

    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo di richiesta non valido.']);
}
?>
