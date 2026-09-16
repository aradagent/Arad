<?php
require_once '../config/database.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

try {
    $action = $_GET['action'] ?? '';
    $userId = $_SESSION['user_id'];
    
    switch ($action) {
        case 'getData':
            $sql = "SELECT * FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Remove sensitive data
                unset($user['iban_number']);
                unset($user['phone_number']);
                
                $response['success'] = true;
                $response['user'] = $user;
                $response['message'] = 'User data retrieved';
            } else {
                $response['message'] = 'User not found';
            }
            break;
            
        case 'getUserId':
            $response['success'] = true;
            $response['userId'] = $userId;
            break;
            
        case 'updateProfile':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!empty($data['first_name']) && !empty($data['last_name'])) {
                $firstName = $conn->real_escape_string($data['first_name']);
                $lastName = $conn->real_escape_string($data['last_name']);
                $phoneNumber = $conn->real_escape_string($data['phone_number'] ?? '');
                
                $sql = "UPDATE users SET 
                        first_name = ?, 
                        last_name = ?, 
                        phone_number = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", $firstName, $lastName, $phoneNumber, $userId);
                
                if ($stmt->execute()) {
                    // Update session
                    $_SESSION['first_name'] = $firstName;
                    $_SESSION['last_name'] = $lastName;
                    
                    $response['success'] = true;
                    $response['message'] = 'Profile updated successfully';
                } else {
                    $response['message'] = 'Failed to update profile';
                }
            } else {
                $response['message'] = 'First name and last name are required';
            }
            break;
            
        case 'uploadAvatar':
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === 0) {
                $uploadDir = avapay_upload_dir('avatars');
                
                // Generate unique filename
                $fileName = 'avatar_' . $userId . '_' . time() . '.jpg';
                $filePath = $uploadDir . $fileName;
                
                // Check file type
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $fileType = mime_content_type($_FILES['avatar']['tmp_name']);
                
                if (in_array($fileType, $allowedTypes)) {
                    // Create image from uploaded file
                    switch($fileType) {
                        case 'image/jpeg':
                            $image = imagecreatefromjpeg($_FILES['avatar']['tmp_name']);
                            break;
                        case 'image/png':
                            $image = imagecreatefrompng($_FILES['avatar']['tmp_name']);
                            break;
                        case 'image/gif':
                            $image = imagecreatefromgif($_FILES['avatar']['tmp_name']);
                            break;
                        case 'image/webp':
                            $image = imagecreatefromwebp($_FILES['avatar']['tmp_name']);
                            break;
                        default:
                            $response['message'] = 'Unsupported image format';
                            echo json_encode($response);
                            exit();
                    }
                    
                    // Create square thumbnail (200x200)
                    $width = imagesx($image);
                    $height = imagesy($image);
                    $size = min($width, $height);
                    
                    $thumb = imagecreatetruecolor(200, 200);
                    imagecopyresampled($thumb, $image, 0, 0, ($width - $size) / 2, ($height - $size) / 2, 200, 200, $size, $size);
                    
                    // Save as JPEG
                    imagejpeg($thumb, $filePath, 85);
                    
                    // Clean up
                    imagedestroy($image);
                    imagedestroy($thumb);
                    
                    // Update database
                    $avatarUrl = 'uploads/avatars/' . $fileName;
                    $sql = "UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("si", $avatarUrl, $userId);
                    
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Avatar uploaded successfully';
                        $response['avatar_url'] = $avatarUrl;
                    } else {
                        $response['message'] = 'Failed to update avatar in database';
                    }
                } else {
                    $response['message'] = 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed';
                }
            } else {
                $response['message'] = 'No file uploaded or upload error';
            }
            break;
            
        default:
            $response['message'] = 'Invalid action';
            break;
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($response);
?>
