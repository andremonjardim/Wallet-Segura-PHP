<!-- // BSD 2-Clause License
Copyright (c) 2024, André Monjardim

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
-->
<?php
// Habilitar exibição de erros
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Inicializar a variável $msg
$msg = ""; // Inicializa a variável
// Processamento do formulário
$escolha = $_GET["escolha"] ?? null;
$submit = $_POST["submit"] ?? null;
$senha = $_POST["senha"] ?? null;
$confirmar_senha = $_POST["confirmar_senha"] ?? null; // Adicionado
$nome_arquivo = $_POST["nome_arquivo"] ?? null;
$conteudo = $_POST["conteudo"] ?? null;
// Funções de criptografia e descriptografia
function generateSalt($length = 16) {
    return bin2hex(random_bytes($length)); // Gerar um salt aleatório
}
function deriveKey($password, $salt) {
    return hash('sha256', $salt . $password, true); // Derivando uma chave a partir da senha e do salt
}
function encrypt($data, $key) {
    $iv_length = openssl_cipher_iv_length('AES-256-CBC');
    $iv = openssl_random_pseudo_bytes($iv_length);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    $hmac = hash_hmac('sha256', $iv . $encrypted, $key, true);
    return base64_encode($iv . $hmac . $encrypted);
}
function decrypt($data, $key) {
    $data = base64_decode($data);
    $iv_length = openssl_cipher_iv_length('AES-256-CBC');
    $hmac_length = 32;
    $iv = substr($data, 0, $iv_length);
    $hmac = substr($data, $iv_length, $hmac_length);
    $encrypted = substr($data, $iv_length + $hmac_length);
    $calculated_hmac = hash_hmac('sha256', $iv . $encrypted, $key, true);
    
    if (!hash_equals($hmac, $calculated_hmac)) {
        throw new Exception('Os dados podem ter sido alterados.');
    }
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}
// Função para compactar o arquivo
function zipWithPassword($filePath, $zipFileName, $password) {
    $zip = new ZipArchive();
    
    if ($zip->open($zipFileName, ZipArchive::CREATE) === TRUE) {
        // Adicionar o arquivo ao ZIP
        $zip->addFile($filePath, basename($filePath));
        // Definir a senha para o ZIP
        $zip->setPassword($password);
        // Proteger o arquivo dentro do ZIP com a senha
        $zip->setEncryptionName(basename($filePath), ZipArchive::EM_AES_256);
        $zip->close();
        return true;
    } else {
        return false;
    }
}
if ($submit) {
    if ($escolha == "criar") {
        if ($senha !== $confirmar_senha) {
            $msg = "<div class='msg'>As senhas não correspondem.</div>";
        } else {
            // Gerar um salt para a senha
            $salt = generateSalt();
            // Derivando a chave da senha com o salt
            $key = deriveKey($senha, $salt);
            // Criptografar o conteúdo e a senha
            $encrypted_senha = encrypt($senha, $key); // Criptografa a senha
            $encrypted_data = encrypt($conteudo, $key);
            
            // Salvar o conteúdo e a senha criptografada em um arquivo
            $file_path = "card/".htmlspecialchars($nome_arquivo); // Sanitizando o nome do arquivo
            
            // Verifica se o arquivo já existe
            if (file_exists($file_path)) {
                $msg = "<div class='msg'>Uma Carteira com esse nome já existe!</div>";
            } else {
                $file_content = $salt . "\n" . $encrypted_senha . "\n" . $encrypted_data; // Salvar o salt junto com os dados
                if (file_put_contents($file_path, $file_content) !== false) {
                    // Compactar o arquivo com senha
                    $zipFileName = "card/$nome_arquivo.zip";
                    if (zipWithPassword($file_path, $zipFileName, $senha)) {
                        // Deletar o arquivo original após a criação do ZIP
                        unlink($file_path);
                        $msg = "<div class='msg'>Arquivo ".htmlspecialchars($nome_arquivo)." criado com sucesso!</div>";
                    } else {
                        $msg = "<div class='msg'>Erro ao criar o arquivo ZIP.</div>";
                    }
                } else {
                    $msg = "<div class='msg'>Erro ao criar o arquivo. Verifique as permissões do diretório.</div>";
                }
            }
        }
    }
elseif ($escolha == "acessar" or $escolha == false) { // NÃO MEXA NESSA LINHA!
    $zipFilePath = "card/".htmlspecialchars($nome_arquivo) . ".zip"; // Sanitizando o nome do arquivo ZIP
    if (file_exists($zipFilePath)) {
        $zip = new ZipArchive;
        if ($zip->open($zipFilePath) === TRUE) {
            // Tentar desbloquear o arquivo zip com a senha
            if ($zip->setPassword($senha)) {
                $fileName = htmlspecialchars($nome_arquivo); // Nome do arquivo dentro do ZIP (sem caminho)
                $contents = $zip->getFromName($fileName); // Lê o arquivo sem extensão
                
                if ($contents !== false) {
                    // O salt deve ser lido do conteúdo do arquivo, já que está criptografado
                    $salt = explode("\n", trim($contents))[0]; // Obtendo o salt da primeira linha

                    // Derivando a chave da senha com o salt
                    $key = deriveKey($senha, $salt);
                    $encrypted_senha = explode("\n", trim($contents))[1]; // Lê a senha criptografada
                    $encrypted_data = explode("\n", trim($contents))[2]; // Lê os dados criptografados

                    try {
                        // Descriptografar a senha
                        $decrypted_senha = decrypt(trim($encrypted_senha), $key);
                        if ($decrypted_senha === $senha) {
                            // Descriptografar o conteúdo do arquivo
                            $decrypted_data = decrypt(trim($encrypted_data), $key);
                            $msg = "<div class='msg2'>" . nl2br(htmlspecialchars($decrypted_data)) . "</div>";
                        } else {
                            $msg = "<div class='msg'>Senha incorreta.</div>";
                        }
                    } catch (Exception $e) {
                        $msg = "<div class='msg'>" . htmlspecialchars($e->getMessage()) . "</div>";
                    }
                } else {
                    $msg = "<div class='msg'>Carteira não encontrada ou senha inválida.</div>";
                }
            } else {
                $msg = "<div class='msg'>Falha ao definir a senha para a Carteira.</div>";
            }
            $zip->close();
        } else {
            $msg = "<div class='msg'>Não foi possível abrir a Carteira.</div>";
        }
    } else {
        $msg = "<div class='msg'>Carteira não encontrada.</div>";
    }
}
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Criptografia</title>
    <script>
        document.getElementById('confirmar_senha').addEventListener('input', validarSenhas);
        function validarSenhas() {
            var senha = document.getElementById('senha').value;
            var confirmarSenha = document.getElementById('confirmar_senha').value;
            var msgErro = document.getElementById('msg_erro');
            var msgSucesso = document.getElementById('msg_sucesso');

            if (senha !== confirmarSenha) {
                msgErro.style.display = 'block';
                msgSucesso.style.display = 'none';
                return false;  // Impede o envio do formulário
            } else {
                msgErro.style.display = 'none';
                msgSucesso.style.display = 'block';
                return true;  // Permite o envio do formulário
            }
        }
    </script>       
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
        }
        .container {
            float: left;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 400px;
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        input[type="password"], input[type="text"] {
            width: 97%;
            height: 30px;
            padding: 10;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
        }
        textarea {
            width: 97%;
            padding: 10;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
        }

        input[type="submit"] {
            width: 100%;
            padding: 10px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button {
            width: 100px;
            height: 30px;
            background-color: #7a7a7a;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
        }
        
        input[type="submit"]:hover, button:hover {
            background-color: #218838;
        }
        .form {
            float: left;
            width: 100%;
        }
        .msg {
            float: left;
            width: 100%;
            margin-top: 10px;
            color: #ff0000;
            font-weight: bold;
        }
        .msg2 {
            float: left;
            margin: 10px;
            text-align: left;
            width: 100%;
        }        
    </style>
</head>
<body>
<div class="container">
    <h1>Sistema Wallet</h1>
    <!-- ÁREA DE CRIAÇÃO -->        
    <?php
    if ($escolha == "criar") {
    ?>
    <div class="form">
        <a href="index.php?escolha=acessar"><button>Início</button></a>
        <form action="" method="post" onsubmit="return validarSenhas()">
            <input type="text" name="nome_arquivo" placeholder="Nome da Carteira" required/>
            <input type="password" id="senha" name="senha" placeholder="Digite sua senha segura" required/>
            <input type="password" id="confirmar_senha" name="confirmar_senha" placeholder="Digite novamente sua senha segura" required/>
            <div id="msg_erro" style="color: red; font-weight: bold; display: none;">As senhas não correspondem.</div>
            <div id="msg_sucesso" style="color: green; display: none;">Senhas correspondem!</div>
            <input name="escolha" type="hidden" value="criar"/>
            <textarea name="conteudo" placeholder="Digite usas palavras-chave aqui..." rows="4" required></textarea>
            <input name="submit" type="submit" value="Criar Carteira">
        </form>
    </div>
    <?php
    } elseif ($escolha != "criar") {
    ?>
    <div class="form">
        <a href="index.php?escolha=criar"><button>Nova Carteira</button></a>
        <form action="" method="post">
            <input type="text" name="nome_arquivo" placeholder="Nome da Carteira" required/>
            <input type="password" name="senha" placeholder="Digite sua senha segura" required/>
            <input name="escolha" type="hidden" value="acessar"/>
            <input name="submit" type="submit" value="Acessar Carteira">
        </form>
    </div>
    <?php
    }
    ?>
    <?php echo $msg; ?>
</div>
</body>
</html>
