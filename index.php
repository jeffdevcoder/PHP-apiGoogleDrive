<?php

require 'vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use Google\Service\Drive\DriveFile as Google_Service_Drive_DriveFile;

class GoogleDriveUpload 
{
    private $filesPaths;
    private $filesNames;
    private $fileIds;

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

        define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID']);
        define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET']);
        define('GOOGLE_REDIRECT_URI', $_ENV['GOOGLE_REDIRECT_URI']);
        define('REFRESH_TOKEN', $_ENV['REFRESH_TOKEN']);

    }

    private function getClient() 
    {
        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setRedirectUri(GOOGLE_REDIRECT_URI);
        $client->addScope(Google_Service_Drive::DRIVE_FILE);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->fetchAccessTokenWithRefreshToken(REFRESH_TOKEN);
        return $client;
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

    public function uploadFiles()
    {
        $client = $this->getClient();
        $service = new Google_Service_Drive($client);
        $folderId = $this->getFolderId($service);

        for ($index = 0; $index < count($this->filesPaths); $index++) {
            $fileName = $this->filesNames[$index];
            $filePath = $this->filesPaths[$index];
            $fileId = $this->getFileIdByName($service, $fileName, $folderId);

            $newFile = new Google_Service_Drive_DriveFile([
                'name' => $fileName
            ]);

            if (file_exists($filePath)) {
                $data = file_get_contents($filePath);

                if ($fileId) {
                    $updatedFile = $service->files->update($fileId, $newFile, [
                        'data' => $data,
                        'mimeType' => 'application/octet-stream',
                        'uploadType' => 'multipart'
                    ]);

                    $parentUpdate = new Google_Service_Drive_DriveFile();
                    $service->files->update($fileId, $parentUpdate, [
                        'addParents' => $folderId,
                        'fields' => 'id, parents'
                    ]);

                    $this->fileIds[] = $updatedFile->getId();
                    break;
                } else {
                    $newFile->setParents([$folderId]);
                    $createdFile = $service->files->create($newFile, [
                        'data' => $data,
                        'mimeType' => 'application/octet-stream',
                        'uploadType' => 'multipart'
                    ]);
                    $this->fileIds[] = $createdFile->getId();
                    break;
                }
            } else {
            throw new Exception("Arquivo não encontrado: $filePath");
            }
        }

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
    'arquivos/teste.zip'
];

$filesNames = [
    'teste.zip'
];

$uploader = new GoogleDriveUpload($filesPaths, $filesNames);
$uploader->uploadFiles();