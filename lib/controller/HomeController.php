<?php

#[Controller('/v1/home')]
class HomeController
{
    #[Get('/hello')]
    public function hello()
    {
        return new DataSuccess('Welcome API PRO Rest API');
    }

    #[Post('/book-room')]
    public function bookRoom()
    {
        // Rate Limiting
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::check($ip, 5, 10)) {
            return new DataFailed("Too many requests. Please slow down.", 429);
        }

        // Parse body
        $body = Node::body(['roomId', 'remarks']);
        $roomId = $body['roomId'];
        $remarks = $body['remarks']??"";
        

        // Distributed Lock (prevent double-booking)
        $lockKey = "room_" . $roomId;
        if (!ProLock::acquire($lockKey, 10)) {
            return new DataFailed("Someone else is currently booking this room. Please wait.", 409);
        }

        // --- Critical Section ---
        // 1. Check DB if room is available
        // 2. Insert booking record
        sleep(2); // Simulate processing
        // --- End Critical Section ---

        ProLock::release($lockKey);

        return new DataSuccess("Successfully booked room $roomId!");
    }

    #[Get('/search')]
    public function searchRooms()
    {
        // This will auto-generate a 'location' input in the UI
        $params = Node::params(['location']);
        $location = $params['location'];
        
        // Optional parameter (doesn't get auto-generated currently since it's not in Node::params)
        $minPrice = $params['min_price'] ?? 0;
        
        return new DataSuccess("Searching rooms in $location with min price $$minPrice");
    }

    #[Post('/upload-avatar')]
    public function uploadAvatar()
    {
        // Auto-generates a text input for userId
        $body = Node::body(['userId']);
        $userId = $body['userId'];

        // Auto-generates a file upload input for avatar
        $files = Node::files(['avatar']);
        $avatar = $files['avatar'];

        $photo = $files['photo'];
        return new DataSuccess("Avatar uploaded successfully for user $userId!", [
            'file_name' => $avatar['name'],
            'size' => $avatar['size'] . ' bytes'
        ]);
    }

    #[Get('/encryption/default')]
    public function encryptionDefault()
    {
        return new DataSuccess("Sample default encrypted response", [
            'message' => 'This data is encrypted using global DATA_ENC key.',
            'timestamp' => time(),
            'random_number' => rand(1000, 9999)
        ]);
    }

    #[Get('/encryption/custom')]
    public function encryptionCustom()
    {
        // Encrypted with custom key 'custom_secret_key'
        return new DataSuccess("Sample custom encrypted response", [
            'message' => 'This data is encrypted using custom_secret_key.',
            'timestamp' => time()
        ], 200, 'custom_secret_key');
    }

    #[Get('/encryption/disabled')]
    public function encryptionDisabled()
    {
        // Explicitly disabled encryption using empty string key override
        return new DataSuccess("Sample unencrypted response", [
            'message' => 'This data is NOT encrypted because encryption key is empty string.',
            'timestamp' => time()
        ], 200, '');
    }
}

