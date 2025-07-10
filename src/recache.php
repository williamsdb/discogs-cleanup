<?php

/**
 * Discogs Cleanup
 * 
 * A simple script to go through all your Discogs collection and highlight those without a grading
 *
 * @author  Neil Thompson <hi@nei.lt>
 * @see     https://nei.lt/discogs-cleanup
 * @version 1.0.0
 * @license GNU Lesser General Public License, version 3
 *
 * I was going through my collection and realised that I had a lot of records
 * without proper grading information. This script aims to help me identify
 * those records and take action.
 *
 **/

class cleanupException extends Exception {}

// set error handling
error_reporting(E_NOTICE);
ini_set('display_errors', 0);
const DEBUG = false;

// have we got a config file?
try {
    require __DIR__ . '/config.php';
} catch (\Throwable $th) {
    throw new cleanupException("config.php file not found. Have you renamed from config_dummy.php?.");
}

// create and connect to the SQLite database to hold the cached data
try {
    // Specify the path and filename for the SQLite database
    $databasePath = __DIR__ . '/cache.sqlite';

    if (!file_exists($databasePath)) {
        // Create a new SQLite database or connect to an existing one
        $pdo = new PDO('sqlite:' . $databasePath);

        // Set error mode to exceptions
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create the necessary tables if they don't already exist
        $sql = "CREATE TABLE IF NOT EXISTS release (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        title TEXT NOT NULL,
                        artist TEXT NOT NULL,
                        format TEXT NULL,
                        type TEXT NULL,
                        image TEXT NULL,
                        dateAdded TEXT NULL,
                        mediaGrading TEXT NULL,
                        sleeveGrading TEXT NULL,
                        url TEXT NULL
                    )";
        $pdo->exec($sql);
    } else {
        // Connect to an existing database
        $pdo = new PDO('sqlite:' . $databasePath);

        // Set error mode to exceptions
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// truncate the table
try {
    $sql = "DELETE FROM release";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// To get headers, re-run with CURLOPT_HEADER true
$ch = curl_init($endpoint . "/users/{$username}");
curl_setopt($ch, CURLOPT_USERAGENT, 'MyDiscogsClient/1.0 +https://nei.lt');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Discogs token={$token}"
]);
curl_setopt($ch, CURLOPT_HEADER, true);

$responseWithHeaders = curl_exec($ch);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$rateLimit = curl_getinfo($ch, CURLINFO_HEADER_OUT); // Not directly available, need to parse headers
$headers = substr($responseWithHeaders, 0, $header_size);
$response = substr($responseWithHeaders, $header_size);
curl_close($ch);

// Call the function with the headers
handleDiscogsRateLimit($headers);

$dets = json_decode($response);

// find the number of items in the collection
$total = $dets->num_collection;

$page = 1;
$i = 0;

// loop through the collection
while ($page <= ($total / 100)) {
    // get the a page of release details
    $ch = curl_init($endpoint . "users/{$username}/collection/folders/0/releases?page=" . $page . "&per_page=100&sort=added&sort_order=desc");
    curl_setopt($ch, CURLOPT_USERAGENT, 'MyDiscogsClient/1.0 +https://nei.lt/now-playing');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Discogs token={$token}"
    ]);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $responseWithHeaders = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rateLimit = curl_getinfo($ch, CURLINFO_HEADER_OUT); // Not directly available, need to parse headers
    $headers = substr($responseWithHeaders, 0, $header_size);
    $response = substr($responseWithHeaders, $header_size);
    curl_close($ch);

    // Call the function with the headers
    handleDiscogsRateLimit($headers);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode != 200) {
        die('Error: ' . $httpCode . ' ' . $response);
    }

    // get the response and convert it to an array
    $dets = json_decode($response);

    // Cycle through the releases
    foreach ($dets->releases as $release) {

        // get the master release information
        $ch = curl_init($release->basic_information->resource_url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MyDiscogsClient/1.0 +https://nei.lt/now-playing');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Discogs token={$token}"
        ]);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $responseWithHeaders = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rateLimit = curl_getinfo($ch, CURLINFO_HEADER_OUT); // Not directly available, need to parse headers
        $headers = substr($responseWithHeaders, 0, $header_size);
        $response = substr($responseWithHeaders, $header_size);
        curl_close($ch);

        // Call the function with the headers
        handleDiscogsRateLimit($headers);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            die('Error: ' . $httpCode . ' ' . $response);
        }
        curl_close($ch);

        $master = json_decode($response);

        // download and cache the image
        $masterUrl = $master->uri;
        $parsedMasterUrl = parse_url($masterUrl, PHP_URL_PATH);
        $masterStub = basename($parsedMasterUrl);

        $imgUrl = $release->basic_information->cover_image;
        // Remove query parameters if needed
        $parsedUrl = parse_url($imgUrl, PHP_URL_PATH);
        $img = basename($parsedUrl);

        // Sanitize filename to remove invalid characters
        $sanitizedImg = preg_replace('/[^\w\-\.]+/u', '_', $img);
        $sanitizedMasterStub = preg_replace('/[^\w\-\.]+/u', '_', $masterStub);

        // have we got an image?
        if (empty($sanitizedImg)) {
            $sanitizedImg = 'nocoverart.jpeg';
        } else {
            // check to see if the image file is already cached
            if (file_exists('./cache/' . $sanitizedMasterStub . '-' . $sanitizedImg)) {
                // do nothing
            } else {
                if ($image = file_get_contents($imgUrl)) {
                    try {
                        file_put_contents('./cache/' . $sanitizedMasterStub . '-' . $sanitizedImg, $image);
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                } else {
                    $sanitizedImg = 'nocoverart.jpeg';
                }
            }
        }

        // Insert a new entry
        $stmt = $pdo->prepare("INSERT INTO release (title, artist, format, type, image, dateAdded, mediaGrading, sleeveGrading, url) VALUES (:title, :artist, :format, :type, :image, :dateAdded, :mediaGrading, :sleeveGrading, :url)");
        $stmt->execute([
            ':title' => $release->basic_information->title,
            ':artist' => $release->basic_information->artists[0]->name,
            ':format' => isset($release->basic_information->formats[0]->name) ? $release->basic_information->formats[0]->name : null,
            ':type' => extractAllowedFormatDescriptions($release->basic_information->formats),
            ':image' => './cache/' . $sanitizedMasterStub . '-' . $sanitizedImg,
            ':dateAdded' => $release->date_added,
            ':mediaGrading' => (
                isset($release->notes) &&
                is_array($release->notes) &&
                isset($release->notes[0]->value)
            ) ? $release->notes[0]->value : null,
            ':sleeveGrading' => (
                isset($release->notes) &&
                is_array($release->notes) &&
                isset($release->notes[1]->value)
            ) ? $release->notes[1]->value : null,
            ':url' => 'https://www.discogs.com/release/' . $masterStub . '-' . $img
        ]);
        echo "Added {$release->basic_information->title} by {$release->basic_information->artists[0]->name}" . PHP_EOL;
        // Pause for 0.5 seconds (500,000 microseconds)
        //usleep(500000);

        $i++;
    }

    // Increment the page number
    $page++;
    echo "Processed page {$page} of " . ceil($total / 100) . " ({$i} releases)" . PHP_EOL;
}

/**
 * Parses Discogs rate limit headers and sleeps if close to the limit.
 *
 * @param string $headers The HTTP response headers.
 */
function handleDiscogsRateLimit($headers)
{
    $rateLimit = null;
    $rateLimitRemaining = null;
    $rateLimitUsed = null;
    foreach (explode("\r\n", $headers) as $header) {
        if (stripos($header, 'X-Discogs-Ratelimit:') === 0) {
            $rateLimit = (int)trim(substr($header, strlen('X-Discogs-Ratelimit:')));
        }
        if (stripos($header, 'X-Discogs-Ratelimit-Remaining:') === 0) {
            $rateLimitRemaining = (int)trim(substr($header, strlen('X-Discogs-Ratelimit-Remaining:')));
        }
        if (stripos($header, 'X-Discogs-Ratelimit-Used:') === 0) {
            $rateLimitUsed = (int)trim(substr($header, strlen('X-Discogs-Ratelimit-Used:')));
        }
    }

    // If close to rate limit, sleep until reset
    if ($rateLimit !== null && $rateLimitRemaining !== null && $rateLimitUsed !== null) {
        if (DEBUG) echo "Rate Limit: {$rateLimit}, Remaining: {$rateLimitRemaining}, Used: {$rateLimitUsed}" . PHP_EOL;
        if ($rateLimitRemaining < 5) {
            if (DEBUG) echo "Approaching Discogs rate limit. Sleeping for 60 seconds..." . PHP_EOL;
            sleep(60);
        }
    } else {
        echo "Rate limit headers not found or incomplete." . PHP_EOL;
        echo $headers . PHP_EOL;
        die();
    }
}

/**
 * Extracts allowed format descriptions from Discogs release formats.
 *
 * @param array $formats the Discogs release formats.
 */
function extractAllowedFormatDescriptions(array $formats): ?string
{
    $allowedFormats = ['12"', 'CD', '7"', '10"', 'LP'];
    $matchingDescriptions = [];

    foreach ($formats as $format) {
        if (isset($format->descriptions) && is_array($format->descriptions)) {
            foreach ($format->descriptions as $desc) {
                if (in_array($desc, $allowedFormats, true)) {
                    $matchingDescriptions[] = $desc;
                }
            }
        }
    }

    return !empty($matchingDescriptions) ? implode(', ', $matchingDescriptions) : null;
}
