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

    #[Post('/raw-json')]
    public function rawJson()
    {
        $body = Node::body();
        Log::info("HomeController::rawJson() - Processing raw JSON request.");
        Log::info("HomeController::rawJson() - Payload body: " . json_encode($body));
        Log::warning("HomeController::rawJson() - This is a test warning log.");
        Log::error("HomeController::rawJson() - This is a test error log.");
        
        $response = (new DataSuccess("Received raw JSON successfully!", [
            'received_body' => $body,
            'timestamp' => time()
        ]))
        ->status(201)
        ->header('X-Test-Fluent-Header', 'Works-Perfectly');

        Log::info("HomeController::rawJson() - Response created with status 201.");
        return $response;
    }

    #[Post('/request-demo')]
    public function requestDemo($request)
    {
        $request->addComment("This endpoint demonstrates the new Request class. It accepts a required name (string), age (int), and an optional active status (bool, default true).");
        
        $name = $request->body->getString('name');
        $age = $request->body->getInt('age');
        $active = $request->body->getBool('active', true);
        
        return new DataSuccess("Demo success!", [
            'name' => $name,
            'age' => $age,
            'active' => $active
        ]);
    }
    #[Post('/upload-image-demo')]
    public function uploadImageDemo(Request $request)
    {
        $request->addComment("This endpoint demonstrates the new multipart property on the Request class.");
        
        $images = $request->multipart->getFiles("images");
        echo json_encode($images);
        
        
        return new DataSuccess("Images received successfully!", [
            'file_name' => $images[0]['name'],
            'size' => $images[0]['size']
        ]);
    }

    #[Post('/test-all-inputs')]
    public function testAllInputs(Request $request)
    {
        $request->addComment("This endpoint tests all available getters (string, int, float, bool, array, object, file, files) across body, query, and multipart sources.");
        
        // Body parameters
        $title = $request->body->getString("title"); // mandatory string
        $score = $request->body->getInt("score", 0); // optional int
        $rating = $request->body->getFloat("rating"); // mandatory float
        $isPublished = $request->body->getBool("isPublished", false); // optional bool
        $tags = $request->body->getArray("tags"); // mandatory array
        $metadata = $request->body->getObject("metadata", ["created" => true]); // optional object

        // Query parameters
        $page = $request->query->getInt("page", 1); // optional int
        $search = $request->query->getString("search", ""); // optional string

        // Multipart (Files)
        $avatar = $request->multipart->getFile("avatar", false, ['jpg', 'png']); // optional single file with format validation
        $documents = $request->multipart->getFiles("documents", true, ['pdf', 'doc', 'docx']); // mandatory multiple files with format validation

        return new DataSuccess("All inputs received and validated successfully!", [
            'body_inputs' => [
                'title' => $title,
                'score' => $score,
                'rating' => $rating,
                'isPublished' => $isPublished,
                'tags' => $tags,
                'metadata' => $metadata,
            ],
            'query_inputs' => [
                'page' => $page,
                'search' => $search,
            ],
            'file_inputs' => [
                'avatar_received' => $avatar !== null,
                'avatar_name' => $avatar !== null ? $avatar['name'] : null,
                'documents_count' => count($documents),
            ]
        ]);
    }

    #[Post('/test-all-files')]
    public function testAllFiles(Request $request)
    {
        $request->addComment("This endpoint demonstrates all possible variations of picking files using getFile and getFiles.");
        
        // Single Files
        $imgMandatory = $request->multipart->getFile("img_mandatory"); // Mandatory, no format
        $imgOptional = $request->multipart->getFile("img_optional", false); // Optional, no format
        $imgFmtReq = $request->multipart->getFile("img_fmt_req", true, ['jpg', 'png']); // Mandatory, strictly jpg/png
        $imgFmtOpt = $request->multipart->getFile("img_fmt_opt", false, ['webp', 'svg']); // Optional, strictly webp/svg
        
        // Multiple Files (Arrays)
        $docsMandatory = $request->multipart->getFiles("docs_mandatory"); // Mandatory, no format
        $docsOptional = $request->multipart->getFiles("docs_optional", false); // Optional, no format
        $docsFmtReq = $request->multipart->getFiles("docs_fmt_req", true, ['pdf']); // Mandatory, strictly pdf
        $docsFmtOpt = $request->multipart->getFiles("docs_fmt_opt", false, ['csv', 'txt']); // Optional, strictly csv/txt

        return new DataSuccess("All files received according to their strict rules!", [
            'single_files' => [
                'img_mandatory_name' => $imgMandatory['name'],
                'img_optional_received' => $imgOptional !== null,
                'img_fmt_req_name' => $imgFmtReq['name'],
                'img_fmt_opt_received' => $imgFmtOpt !== null,
            ],
            'multiple_files' => [
                'docs_mandatory_count' => count($docsMandatory),
                'docs_optional_received' => $docsOptional !== null,
                'docs_fmt_req_count' => count($docsFmtReq),
                'docs_fmt_opt_received' => $docsFmtOpt !== null,
            ]
        ]);
    }
}

