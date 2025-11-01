<?php

// --- CONFIGURATION ---
// !! REPLACE with your Bot Token from @BotFather
define('BOT_TOKEN', 'YOUR BOT TOKEN');
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// -- Database Credentials --
// !! REPLACE with your MySQL database details
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR DATABASE NAME');
define('DB_USER', 'root');
define('DB_PASS', ''); // e.g., 'your_password'

// -- Encryption Settings --
// These keys are pre-generated and secure.
define('ENCRYPTION_METHOD', 'aes-256-cbc');
define('ENCRYPTION_KEY', hash('sha256', 'bK9p@7zX$qG!rT$2vY+W#jH&mN*uE4aQ', true));
define('ENCRYPTION_IV', substr(hash('sha256', 'sFpD%6hK@zVb!8eRjG*pA&uT#qWc$3k', true), 0, 16));


// --- HELPER FUNCTIONS ---

/**
 * Establishes a PDO database connection.
 * @return PDO
 */
function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Do not expose detailed errors to the user
            error_log('Database Connection Error: ' . $e->getMessage());
            // This will be caught by the main error handler
            throw new Exception('Database connection failed.');
        }
    }
    return $pdo;
}

/**
 * Sends a message to a specific chat via the Telegram API.
 * @param int $chat_id
 * @param string $text
 */
function sendMessage(int $chat_id, string $text): void {
    $url = TELEGRAM_API_URL . 'sendMessage';
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
    ];

    // Use cURL for robust HTTP requests
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
    
    // Alternative: file_get_contents (less robust, may be disabled on some hosts)
    // $options = [
    //     'http' => [
    //         'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
    //         'method'  => 'POST',
    //         'content' => http_build_query($data),
    //     ],
    // ];
    // $context = stream_context_create($options);
    // file_get_contents($url, false, $context);
}

/**
 * Encrypts plaintext using AES-256-CBC.
 * @param string $plaintext
 * @return string (Base64-encoded ciphertext)
 */
function encryptText(string $plaintext): string {
    $encrypted = openssl_encrypt(
        $plaintext,
        ENCRYPTION_METHOD,
        ENCRYPTION_KEY,
        OPENSSL_RAW_DATA, // Output raw bytes
        ENCRYPTION_IV
    );
    if ($encrypted === false) {
        throw new Exception('Encryption failed.');
    }
    return base64_encode($encrypted);
}

/**
 * Decrypts ciphertext.
 * @param string $base64_ciphertext
 * @return string (Original plaintext)
 */
function decryptText(string $base64_ciphertext): string {
    $ciphertext = base64_decode($base64_ciphertext);
    if ($ciphertext === false) {
        throw new Exception('Base64 decode failed.');
    }

    $decrypted = openssl_decrypt(
        $ciphertext,
        ENCRYPTION_METHOD,
        ENCRYPTION_KEY,
        OPENSSL_RAW_DATA, // Input is raw bytes
        ENCRYPTION_IV
    );
    if ($decrypted === false) {
        throw new Exception('Decryption failed. Key/IV mismatch or corrupt data.');
    }
    return $decrypted;
}

/**
 * Generates a cryptographically secure 16-digit numeric key
 * and ensures it is unique in the database.
 * @param PDO $pdo
 * @return string
 */
function generateUniqueKey(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT 1 FROM encrypted_texts WHERE key_code = ?");
    
    do {
        $key = '';
        for ($i = 0; $i < 16; $i++) {
            $key .= random_int(0, 9);
        }
        
        $stmt->execute([$key]);
        $exists = $stmt->fetchColumn();
    } while ($exists); // Loop until a unique key is found

    return $key;
}

/**
 * Stores the key and encrypted data in the database.
 * @param PDO $pdo
 * @param string $key
 * @param string $encrypted_data
 */
function storeInDB(PDO $pdo, string $key, string $encrypted_data): void {
    $stmt = $pdo->prepare("INSERT INTO encrypted_texts (key_code, encrypted_data) VALUES (?, ?)");
    if (!$stmt->execute([$key, $encrypted_data])) {
        throw new Exception('Database write failed.');
    }
}

/**
 * Retrieves encrypted data from the database using a key.
 * @param PDO $pdo
 * @param string $key
 * @return string|null (The encrypted data or null if not found)
 */
function retrieveFromDB(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare("SELECT encrypted_data FROM encrypted_texts WHERE key_code = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    
    return $result ?: null;
}


// --- WEBHOOK PROCESSING ---

// Set a default chat_id for error reporting, though it will be overwritten
$chat_id_on_error = null;

try {
    // 1. Get incoming update from Telegram
    $json = file_get_contents('php://input');
    $update = json_decode($json, true);

    // 2. Validate update and extract key info
    if (!$update || !isset($update['message'])) {
        // Not a message update, ignore it (e.g., channel post, edit)
        exit;
    }

    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $chat_id_on_error = $chat_id; // Set for the catch block

    // 3. Handle non-text messages
    if (!isset($message['text'])) {
        sendMessage($chat_id, "Please send text only.");
        exit;
    }

    $text = $message['text'];
    
    // 4. Get Database Connection
    $pdo = getDBConnection();

    // 5. --- ROUTING ---
    
    // Handle /start command
    if ($text === '/start') {
        sendMessage($chat_id, "Send me text to encrypt and get a 16-digit key. Send the key to decrypt.");
    
    // Handle Decryption (Input is exactly 16 digits)
    } else if (preg_match('/^\d{16}$/', $text)) {
        $key = $text;
        $encrypted_data = retrieveFromDB($pdo, $key);
        
        if ($encrypted_data) {
            $decrypted_text = decryptText($encrypted_data);
            sendMessage($chat_id, $decrypted_text); // Reply with only the plaintext
        } else {
            sendMessage($chat_id, "Invalid key"); // Reply with exact error
        }

    // Handle Encryption (Any other text)
    } else {
        if (empty(trim($text))) {
            sendMessage($chat_id, "Cannot encrypt empty text.");
            exit;
        }

        // 1. Encrypt the text
        $encrypted_text = encryptText($text);

        // 2. Generate a unique key
        $key = generateUniqueKey($pdo);
        
        // 3. Store in database
        storeInDB($pdo, $key, $encrypted_text);
        
        // 4. Reply with *only* the key
        sendMessage($chat_id, $key);
    }

} catch (Exception $e) {
    // Generic error handling
    error_log('Bot Error: ' . $e->getMessage());
    if ($chat_id_on_error) {
        sendMessage($chat_id_on_error, "An error occurred. Please try again.");
    }

}
