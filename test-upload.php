<?php
// TEST-FIL för bilduppladdning
// Ladda upp denna fil och navigera till den i webbläsaren
// för att testa om bilduppladdning fungerar

require_once 'config.php';

// Hantera uppladdning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_image'])) {
    ob_clean();
    header('Content-Type: application/json');

    $result = uploadImage($_FILES['test_image']);
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Bilduppladdning</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            white-space: pre-wrap;
        }
        .success { background-color: #d4edda; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <h1>🧪 Test Bilduppladdning</h1>

    <h2>Systeminformation:</h2>
    <ul>
        <li><strong>PHP Version:</strong> <?= phpversion() ?></li>
        <li><strong>IMAGE_DIR:</strong> <?= IMAGE_DIR ?></li>
        <li><strong>IMAGE_DIR finns:</strong> <?= file_exists(IMAGE_DIR) ? '✅ JA' : '❌ NEJ' ?></li>
        <li><strong>IMAGE_DIR skrivbar:</strong> <?= is_writable(IMAGE_DIR) ? '✅ JA' : '❌ NEJ' ?></li>
        <li><strong>upload_max_filesize:</strong> <?= ini_get('upload_max_filesize') ?></li>
        <li><strong>post_max_size:</strong> <?= ini_get('post_max_size') ?></li>
    </ul>

    <hr>

    <h2>Testa uppladdning:</h2>
    <form id="uploadForm" enctype="multipart/form-data">
        <input type="file" name="test_image" accept="image/*" required>
        <button type="submit">Ladda upp testbild</button>
    </form>

    <div id="result"></div>

    <script>
        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = new FormData();
            const fileInput = document.querySelector('input[type="file"]');
            formData.append('test_image', fileInput.files[0]);

            const resultDiv = document.getElementById('result');
            resultDiv.innerHTML = '<p>Laddar upp...</p>';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const text = await response.text();

                try {
                    const result = JSON.parse(text);

                    if (result.success) {
                        resultDiv.className = 'result success';
                        resultDiv.innerHTML = `
                            <strong>✅ LYCKADES!</strong><br>
                            Filnamn: ${result.filename}<br>
                            Sökväg: data/images/${result.filename}
                        `;
                    } else {
                        resultDiv.className = 'result error';
                        resultDiv.innerHTML = `
                            <strong>❌ FEL:</strong><br>
                            ${result.error}
                        `;
                    }
                } catch (e) {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = `
                        <strong>❌ PARSE ERROR:</strong><br>
                        Kunde inte parse JSON. Servern returnerade:<br>
                        <pre>${text}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = `
                    <strong>❌ FETCH FAILED:</strong><br>
                    ${error.message}
                `;
            }
        });
    </script>
</body>
</html>
