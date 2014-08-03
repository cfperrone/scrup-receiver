<?php

/**
 * An image uploader for requests sent from Scrup (https://github.com/rsms/scrup/)
 * or any custom application that sends POST requests in the following format:
 * http://img.example.com/path/to/uploader/?filename={filename}
 * where the request body is the binary file
 *
 * An HTML table of uploaded images is returned by sending a GET request
 */
class ImageUploader {
    private $helper;

    public function __construct() {
        $this->helper = new Helper();
    }

    // Main request entry point
    public function route() {
        if ($this->helper->isGet()) {
            $this->doListContent();
        } else if ($this->helper->isPost()) {
            $this->doUpload();
        } else {
            $this->helper->respondWithError("I don't know what you're trying to do...",
                                            Helper::HTTP_STATUS_NOT_IMPLEMENTED);
        }
    }

    // Lists all uploaded images and their metadata
    private function doListContent() {
        $images = $this->helper->getUploadedImages();
        echo "<!DOCTYPE html>\n";
        echo "<html lang=\"en\">\n";
        echo "<head>\n";
        echo "<meta charset=\"utf-8\">\n";
        echo "<title>Image List</title>\n";
        echo "</head>\n<body>\n";
        echo "<h1>Image List</h1>\n";

        if (count($images)) {
            echo "<table border=\"1\">";
            foreach ($images as $i => $image) {
                $file = $image['filename'];
                $date = strftime('%D %r', $image['date']);
                echo "<tr>";
                echo "<td>$i</td>";
                echo "<td><a href=\"/i/$file\"><img style=\"max-width: 500px;\" src=\"/i/$file\"</a></td>";
                echo "<td>$date</td>";
                echo "</tr>\n";
            }
            echo "</table>";
        } else {
            echo "<h4>There are no images</h4>\n";
        }
        echo "</body>\n</html>";
    }

    // Receives an upload and saves it, returning its public URL
    private function doUpload() {
        $name = $this->helper->getGet('name', strval(time()));
        $filename = $this->helper->generatePathFromFilename($name);
        $url = $this->helper->generateURLFromPath($filename);

        try {
            $size = $this->helper->saveFile($filename);
        } catch (Exception $e) {
            $this->helper->respondWithError($e->getMessage(), Helper::HTTP_STATUS_SERVER_ERROR);
        }

        // If the input was empty, delete the file and return an error
        if ($size == 0) {
            $this->helper->deleteFile($filename);
            $this->helper->respondWithError("Input file was empty",
                                            Helper::HTTP_STATUS_BAD_REQUEST);
        }

        // Make sure the input wasn't too large
        if ($size >= Helper::MAX_UPLOAD_SIZE) {
            // Delete the file because we didn't save the whole thing
            $this->helper->deleteFile($filename);
            $this->helper->respondWithError("Input file too large. Must be smaller than " . Helper::MAX_UPLOAD_SIZE . " bytes",
                                            Helper::HTTP_STATUS_REQUEST_TOO_LARGE);
        }

        $this->helper->setStatus(Helper::HTTP_STATUS_CREATED);
        $this->helper->setHeader(Helper::HEADER_CONTENT_TYPE, 'text/plain; charset=utf-8');
        $this->helper->setHeader(Helper::HEADER_CONTENT_LENGTH, strlen($url));
        echo $url;
    }
}

class Helper {
    private $_is_post;
    private $_is_get;
    private $_is_https;
    private $_image_dir;

    const PROTOCOL = 'HTTP/1.1';

    const HTTP_STATUS_OK                = 200;
    const HTTP_STATUS_CREATED           = 201;
    const HTTP_STATUS_BAD_REQUEST       = 400;
    const HTTP_STATUS_UNAUTHORIZED      = 401;
    const HTTP_STATUS_FORBIDDEN         = 403;
    const HTTP_STATUS_REQUEST_TOO_LARGE = 413;
    const HTTP_STATUS_SERVER_ERROR      = 500;
    const HTTP_STATUS_NOT_IMPLEMENTED   = 501;

    const HEADER_CONTENT_TYPE       = 'Content-Type';
    const HEADER_CONTENT_LENGTH     = 'Content-Length';

    const MAX_UPLOAD_SIZE = 10240000; // 10MB

    // String representations of the HTTP_STATUS_* list above
    static private $http_status_text = array(
        self::HTTP_STATUS_OK => 'OK',
        self::HTTP_STATUS_CREATED => 'Created',
        self::HTTP_STATUS_BAD_REQUEST => 'Bad Request',
        self::HTTP_STATUS_UNAUTHORIZED => 'Unauthorized',
        self::HTTP_STATUS_FORBIDDEN => 'Forbidden',
        self::HTTP_STATUS_REQUEST_TOO_LARGE => 'Request Entity Too Large',
        self::HTTP_STATUS_SERVER_ERROR => 'Internal Server Error',
        self::HTTP_STATUS_NOT_IMPLEMENTED => 'Not Implemented',
    );

    // A list of vaild file extensions for display in the upload list
    static public $valid_extensions = array(
        'jpg', 'jpeg', 'gif', 'png', 'bmp',
    );

    public function __construct() {
        $this->_is_post = ($_SERVER['REQUEST_METHOD'] == 'POST');
        $this->_is_get = ($_SERVER['REQUEST_METHOD'] == 'GET');
        $this->_is_https = array_key_exists('HTTPS', $_SERVER);
        $this->_image_dir = __DIR__ . '/i';
    }

    // Returns true if the request is a GET
    public function isGet() {
        return $this->_is_get;
    }
    // Returns true if the request is a POST
    public function isPost() {
        return $this->_is_post;
    }
    // Returns true if the request is using HTTPS
    public function isHTTPS() {
        return $this->_is_https;
    }
    // Gets a GET request variable or $default if it isn't specified
    public function getGet($field, $default=null) {
        if (!array_key_exists($field, $_GET)) {
            return $default;
        }
        return $_GET[$field];
    }
    // Same as getGet above but for POST requests
    public function getPost($field, $default=null) {
        if (!array_key_exists($field, $_POST)) {
            return $default;
        }
        return $_POST[$field];
    }
    // Gets the image directory path
    public function getImageDir() {
        return $this->_image_dir;
    }

    // Sets an HTTP status $code
    public function setStatus($code) {
        if (!array_key_exists($code, self::$http_status_text)) {
            throw new InvalidArgumentException("HTTP status $code not implemented");
        }
        if (headers_sent()) {
            throw new Exception("Headers have already been sent");
        }

        $text = self::$http_status_text[$code];
        header(self::PROTOCOL . " {$code} {$text}");
    }

    // Sets an arbitrary HTTP header
    public function setHeader($name, $value) {
        if (headers_sent()) {
            throw new Exception("Headers have already been sent");
        }

        if (is_null($name)) {
            throw new InvalidArgumentException("Header name cannot be null");
        }

        header("$name: $value");
    }

    // Send an error response to the client. This includes an HTTP status $code
    // as well as an HTML response indicating the $code and a $message
    public function respondWithError($message = "", $code = self::HTTP_400_BAD_REQUEST) {
        if ($code < 400 || $code > 599) {
            throw new InvalidArgumentException("$code is not an error status");
        }

        $this->setStatus($code);

        $text = self::$http_status_text[$code];
        echo "<h1>HTTP $code: $text </h1>";
        echo "<p>$message</p>";
        exit();
    }

    // -- Image helpers

    // Get an array of all the uploaded images including file metadata
    public function getUploadedImages() {
        if (!is_dir($this->getImageDir())) {
            throw new Exception("Cannot find image directory");
        }

        // Get all the files in the image directory filtered by images
        $images = array_filter(
            scandir($this->getImageDir()),
            function($item) {
                if ($item == '.' || $item == '..') {
                    return false;
                }

                $ext = pathinfo($item, PATHINFO_EXTENSION);
                if (!in_array(strtolower($ext), Helper::$valid_extensions)) {
                    return false;
                }

                return true;
            });

        // Get the metadata for the files
        $images_enhanced = array();
        foreach ($images as $img) {
            $path = $this->getImageDir() . '/' . $img;
            $images_enhanced[] = array(
                'filename' => $img,
                'path' => $path,
                'date' => filemtime($path),
            );
        }

        // Order results by date in reverse
        usort($images_enhanced,
            function($thing1, $thing2) {
                return $thing2['date'] - $thing1['date'];
            });

        return $images_enhanced;
    }

    // Based on an uploaded filename, create a hash'd path
    public function generatePathFromFilename($filename) {
        $name = substr(base_convert(md5($filename.' '.$_SERVER['REMOTE_ADDR']), 16, 36),0,15);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        return "$name.$ext";
    }

    // From a hash'd file path create a URL to be returned to the client
    public function generateURLFromPath($path) {
        $protocol = $this->isHTTPS()?"https":"http";
        $server = $_SERVER['SERVER_NAME'];

        return "$protocol://$server/i/$path";
    }

    public function saveFile($filename) {
        $dest_stream = fopen($this->getImageDir() . '/' . $filename, 'w');
        if ($dest_stream === false) {
            throw new Exception("Could not open file for writing");
        }

        $src_stream = fopen('php://input', 'r');
        if ($src_stream === false) {
            throw new Exception("Could not open input for reading");
        }

        $size = stream_copy_to_stream($src_stream, $dest_stream, self::MAX_UPLOAD_SIZE);
        fclose($src_stream);
        fclose($dest_stream);

        return $size;
    }

    public function deleteFile($filename) {
        unlink($this->getImageDir() . '/' . $filename);
    }

}

$class = new ImageUploader();
$class->route();
