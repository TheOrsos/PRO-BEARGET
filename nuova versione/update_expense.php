<?php
session_start();
header('Content-Type: application/json');

require_once 'db_connect.php';
require_once 'functions.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Accesso non autorizzato.']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION['id'];
    $expense_id = $_POST['expense_id'] ?? 0;
    $fund_id = $_POST['fund_id'] ?? 0;
    $description = trim($_POST['description'] ?? '');
    $amount = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $expense_date = $_POST['expense_date'] ?? '';
    $paid_by_user_id = $_POST['paid_by_user_id'] ?? 0;
    $category_id = $_POST['category_id'] ?? 0;
    $delete_attachment = isset($_POST['delete_attachment']);

    // --- Validazione ---
    if (empty($expense_id) || empty($fund_id) || empty($description) || $amount === false || empty($expense_date) || empty($paid_by_user_id) || empty($category_id)) {
        echo json_encode(['success' => false, 'message' => 'Tutti i campi sono obbligatori.']);
        exit;
    }

    // --- Controllo Permessi ---
    $members = get_fund_members($conn, $fund_id);
    $is_member = false;
    foreach ($members as $member) {
        if ($member['id'] == $user_id) {
            $is_member = true;
            break;
        }
    }
    if (!$is_member) {
        echo json_encode(['success' => false, 'message' => 'Non sei un membro di questo fondo.']);
        exit;
    }

    $conn->begin_transaction();

    try {
        // --- Ottieni spesa corrente per recuperare vecchio importo e percorso allegato ---
        $sql_get_expense = "SELECT * FROM group_expenses WHERE id = ? AND fund_id = ?";
        $stmt_get = $conn->prepare($sql_get_expense);
        $stmt_get->bind_param("ii", $expense_id, $fund_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        $current_expense = $result->fetch_assoc();
        $stmt_get->close();

        if (!$current_expense) {
            throw new Exception("Spesa non trovata o non appartenente al fondo specificato.");
        }

        $old_amount = $current_expense['amount'];
        $old_paid_by_user_id = $current_expense['paid_by_user_id'];
        $attachment_path = $current_expense['attachment_path'];

        // --- Gestione Allegato ---
        if ($delete_attachment && !empty($attachment_path)) {
            if (file_exists($attachment_path)) {
                unlink($attachment_path);
            }
            $attachment_path = null;
        }

        if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] == 0) {
            // Se c'era un vecchio allegato, cancellalo
            if (!empty($attachment_path) && file_exists($attachment_path)) {
                unlink($attachment_path);
            }

            $target_dir = "uploads/attachments/";
            $file_extension = pathinfo($_FILES["attachment_file"]["name"], PATHINFO_EXTENSION);
            $safe_filename = uniqid('attachment_', true) . '.' . $file_extension;
            $new_attachment_path = $target_dir . $safe_filename;

            // Validazione file
            $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array(strtolower($file_extension), $allowed_types)) {
                throw new Exception("Tipo di file non supportato.");
            }
            if ($_FILES["attachment_file"]["size"] > 2097152) { // 2MB
                throw new Exception("Il file è troppo grande (max 2MB).");
            }

            if (move_uploaded_file($_FILES["attachment_file"]["tmp_name"], $new_attachment_path)) {
                $attachment_path = $new_attachment_path;
            } else {
                throw new Exception("Errore durante il caricamento del file.");
            }
        }

        // --- Aggiornamento Database ---

        // TODO: Gestire la ricalcolo degli split se l'importo o il pagante cambiano.
        // Per ora, questa logica è semplificata e non ricalcola gli split.
        // Se l'importo o il pagante sono cambiati, è necessario:
        // 1. Annullare l'impatto della vecchia transazione sul saldo del vecchio pagante.
        // 2. Applicare l'impatto della nuova transazione sul saldo del nuovo pagante.
        // 3. Cancellare i vecchi record da `expense_splits`.
        // 4. Creare nuovi record in `expense_splits` basati sul nuovo importo.
        // La UI nel modal di modifica non supporta ancora la modifica della divisione,
        // quindi per ora si assume che la divisione rimanga la stessa (es. parti uguali).

        // Aggiorna la tabella group_expenses
        $sql_update = "UPDATE group_expenses SET description = ?, amount = ?, expense_date = ?, paid_by_user_id = ?, category_id = ?, attachment_path = ? WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("sdsiisi", $description, $amount, $expense_date, $paid_by_user_id, $category_id, $attachment_path, $expense_id);

        if (!$stmt_update->execute()) {
            throw new Exception("Errore durante l'aggiornamento della spesa.");
        }
        $stmt_update->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Spesa aggiornata con successo.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Metodo di richiesta non valido.']);
}
?>
