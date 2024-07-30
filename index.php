<?php

ini_set('memory_limit', '2048M');
ini_set('max_execution_time', '1800');

require 'vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use Google\Service\Drive\DriveFile as Google_Service_Drive_DriveFile;
use GuzzleHttp\Client as GuzzleHttpClient;

class GoogleDriveUpload 
{
    private $filesPaths;
    private $filesNames;
    private $fileIds;
    private $folderName;

    public function __construct($filesPaths, $filesNames, $folderName = 'UploadedFiles') 
    {
        $this->filesPaths = $filesPaths;
        $this->filesNames = $filesNames;
        $this->fileIds = [];
        $this->folderName = $folderName;

        if (file_exists(__DIR__ . '/.env')) {
            $dotenv = Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        } else {
            throw new Exception('.env file not found');
        }

        $this->googleClientId = $_ENV['GOOGLE_CLIENT_ID'];
        $this->googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'];
        $this->googleRedirectUri = $_ENV['GOOGLE_REDIRECT_URI'];
        $this->refreshToken = $_ENV['REFRESH_TOKEN'];
    }

    private function getClient() 
    {
        $client = new Google_Client();
        $client->setClientId($this->googleClientId);
        $client->setClientSecret($this->googleClientSecret);
        $client->setRedirectUri($this->googleRedirectUri);
        $client->addScope(Google_Service_Drive::DRIVE_FILE);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->fetchAccessTokenWithRefreshToken($this->refreshToken);
        return $client;
    }

    private function addArchive($service, $folderId) 
    {
        $zipFileName = "{$this->folderName}.zip";
        $zipFilePath = sys_get_temp_dir() . '/' . $zipFileName;
        $zip = new ZipArchive();

        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($this->filesPaths as $key => $path) {
                if (file_exists($path)) {
                    $zip->addFile($path, $this->filesNames[$key]);
                } else {
                    throw new Exception("Arquivo não encontrado: $path");
                }
            }
            $zip->close();
        } else {
            throw new Exception("Não foi possível criar o arquivo zip: $zipFilePath");
        }

        $fileId = $this->getFileIdByName($service, $zipFileName, $folderId);
        $newFile = new Google_Service_Drive_DriveFile([
            'name' => $zipFileName
        ]);

        $this->uploadFileInChunks($service, $newFile, $zipFilePath, $fileId, $folderId);
    }

    private function getFolderId($service)
    {
        $folderId = null;
        $folders = $service->files->listFiles([
            'q' => "mimeType='application/vnd.google-apps.folder' and name='{$this->folderName}' and trashed=false"
        ]);

        if (count($folders->getFiles()) == 0) {
            $newFolder = new Google_Service_Drive_DriveFile([
                'name' => $this->folderName,
                'mimeType' => 'application/vnd.google-apps.folder'
            ]);

            $folder = $service->files->create($newFolder, [
                'fields' => 'id'
            ]);
            $folderId = $folder->id;
        } else {
            $folderId = $folders->getFiles()[0]->getId();
        }

        return $folderId;
    }

    private function getFileIdByName($service, $fileName, $folderId) 
    {
        $files = $service->files->listFiles([
            'q' => "name='$fileName' and '{$folderId}' in parents and trashed=false"
        ]);

        if (count($files->getFiles()) > 0) {
            return $files->getFiles()[0]->getId();
        }

        return null;
    }

    private function uploadFileInChunks($service, $newFile, $zipFilePath, $fileId, $folderId)
    {
        $client = new GuzzleHttpClient(['timeout' => 1200]);
        $fileSize = filesize($zipFilePath);
        $chunkSize = 128 * 1024;
        $handle = fopen($zipFilePath, 'rb');

        if ($fileId) {
            $url = "https://www.googleapis.com/upload/drive/v3/files/{$fileId}?uploadType=resumable";
        } else {
            $newFile->setParents([$folderId]);
            $url = "https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable";
        }

        $accessToken = $service->getClient()->getAccessToken()['access_token'];
        $headers = [
            'Authorization' => 'Bearer '. $accessToken,
            'Content-Length' => $fileSize,
            'Content-Type' => 'application/zip'
        ];

        $retryCount = 0;
        $maxRetries = 3;
        $backoff = 1;

        while ($retryCount < $maxRetries) {
            try {
                $response = $client->post($url, [
                    'headers' => $headers,
                    'body' => ''
                ]);

                $uploadUrl = $response->getHeader('Location')[0];

                $offset = 0;
                while (!feof($handle)) {
                    $chunk = fread($handle, $chunkSize);
                    $contentRange = "bytes {$offset}-". ($offset + strlen($chunk) - 1). "/{$fileSize}";

                    try {
                        $client->put($uploadUrl, [
                            'headers' => [
                                'Authorization' => 'Bearer '. $accessToken,
                                'Content-Length' => strlen($chunk),
                                'Content-Range' => $contentRange,
                            ],
                            'body' => $chunk
                        ]);

                        if ($offset > 0 && $offset % (128 * 1024) == 0) {
                            echo "Enviando...\n";
                            flush();
                        }
                    } catch (GuzzleHttp\Exception\RequestException $e) {
                        if ($e->getCode() == 408) {
                            $retryCount++;
                            sleep($backoff);
                            $backoff *= 2;
                            continue;
                        } else {
                            fclose($handle);
                            throw $e;
                        }
                    }

                    $offset += strlen($chunk);
                }

                fclose($handle);

                $this->fileIds[] = $fileId;
                return;
            } catch (GuzzleHttp\Exception\RequestException $e) {
                if ($e->getCode() == 408) {
                    $retryCount++;
                    sleep($backoff);
                    $backoff *= 2;
                    continue;
                } else {
                    fclose($handle); 
                    throw $e;
                }
            }
        }

        fclose($handle);
        echo "Erro de upload: ". $e->getMessage(). "\n";
    }

    public function uploadFiles()
    {
        $client = $this->getClient();
        $service = new Google_Service_Drive($client);
        $folderId = $this->getFolderId($service);

        $this->addArchive($service, $folderId);

        $this->printIds();
    }

    private function printIds()
    {
        echo "Arquivos enviados com sucesso. IDs dos arquivos:\n";
        foreach ($this->fileIds as $fileId) {
            echo $fileId . "\n";
        }
    }
}

$filesPaths = [
    './arquivos/serenidade.sql',
    './arquivos/eternidadeliesse.sql',
    './arquivos/eternidade.sql'
];

$filesNames = [
    'serenidade.sql',
    'eternidadeliesse.sql',
    'eternidade.sql'
];

$uploader = new GoogleDriveUpload($filesPaths, $filesNames);
$uploader->uploadFiles();
