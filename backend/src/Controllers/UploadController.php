<?php
namespace App\Controllers;

use Exception;

class UploadController extends BaseController {
    public function upload(): void {
        $uploadDir = __DIR__ . '/../../public/uploads/helpdesk/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // 1. Check for standard multipart $_FILES
        if (!empty($_FILES['file'])) {
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $this->error('File upload error: ' . $file['error'], 400);
                return;
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'm4v', 'webm'];
            if (!in_array($ext, $allowed)) {
                $this->error('Invalid file type. Allowed: JPG, PNG, GIF, WEBP, MP4, MOV, WEBM', 400);
                return;
            }

            $filename = 'media_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destination = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $type = in_array($ext, ['mp4', 'mov', 'm4v', 'webm']) ? 'video' : 'image';
                $url = 'uploads/helpdesk/' . $filename;
                $this->success([
                    'url' => $url,
                    'type' => $type,
                    'name' => $file['name'],
                    'size' => $file['size']
                ], 'File uploaded successfully', 201);
                return;
            } else {
                $this->error('Failed to move uploaded file', 500);
                return;
            }
        }

        // 2. Check for base64 JSON payload
        $input = $this->getJsonInput();
        if (!empty($input['base64'])) {
            $base64 = $input['base64'];
            $type = $input['type'] ?? 'image';
            $ext = $input['ext'] ?? ($type === 'video' ? 'mp4' : 'jpg');

            // Strip header if present (e.g. data:image/jpeg;base64,)
            if (preg_match('/^data:(image|video)\/(\w+);base64,/', $base64, $match)) {
                $type = $match[1];
                $ext = $match[2] === 'jpeg' ? 'jpg' : $match[2];
                $base64 = substr($base64, strpos($base64, ',') + 1);
            }

            $decoded = base64_decode($base64);
            if ($decoded === false) {
                $this->error('Failed to decode base64 file', 400);
                return;
            }

            $filename = 'media_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destination = $uploadDir . $filename;

            if (file_put_contents($destination, $decoded)) {
                $url = 'uploads/helpdesk/' . $filename;
                $this->success([
                    'url' => $url,
                    'type' => $type,
                    'name' => $filename,
                    'size' => strlen($decoded)
                ], 'File uploaded successfully', 201);
                return;
            } else {
                $this->error('Failed to save decoded file', 500);
                return;
            }
        }

        $this->error('No file or base64 data provided', 400);
    }
}
