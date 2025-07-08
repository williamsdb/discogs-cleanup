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

// have we got a config file?
try {
    require __DIR__ . '/config.php';
} catch (\Throwable $th) {
    throw new cleanupException("config.php file not found. Have you renamed from config_dummy.php?.");
}

// has a recache been requested?
if (isset($_GET['act']) && $_GET['act'] === 'recache') {
    // Absolute path to the script
    $script = __DIR__ . '/recache.php';

    // Escape the path for safety
    $escapedScript = escapeshellarg($script);

    // Command to run in background
    $cmd = "php $escapedScript > /dev/null 2>&1 &";
    $output = [];
    $out = '';
    $return_var = 0;

    $last_line = exec($cmd, $output, $return_var);

    if ($return_var !== 0) {
        $out = "<p>Recache failed with return code $return_var.</p>";
    } else {
        $out = "<p>Recache started successfully.</p>";
    }
}

// create and connect to the SQLite database to hold the cached data
try {
    // Specify the path and filename for the SQLite database
    $databasePath = './cache.sqlite';

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
                        format TEXT,
                        image TEXT,
                        dateAdded TEXT,
                        mediaGrading TEXT,
                        sleeveGrading TEXT,
                        url TEXT
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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimal-ui, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- Favicon -->
    <link rel="shortcut icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" sizes="57x57" href="/favicon-57x57.png">
    <link rel="apple-touch-icon" sizes="72x72" href="/favicon-72x72.png">
    <link rel="apple-touch-icon" sizes="114x114" href="/favicon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="/favicon-120x120.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/favicon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon-180x180.png">

    <!-- Stylesheets and Scripts -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" />
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>


    <title>Discogs Collection Cleanup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #FFFFFF;
            color: #000000;
            width: 80%;
            margin: 0 auto;
            padding: 20px;
            position: relative;
        }

        thead input {
            width: 100%;
            box-sizing: border-box;
            font-size: 0.9em;
            padding: 3px;
        }

        a:visited,
        a:link {
            color: #000000;
        }

        .results-table {
            margin-bottom: 20px;
        }

        .built-by {
            padding: 10px 0;
            border-top: 1px solid lightgray;
        }

        /* The switch - the box around the slider */
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }

        /* Hide default HTML checkbox */
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        /* The slider */
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .4s;
            transition: .4s;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 12px;
            width: 12px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
        }

        input:checked+.slider {
            background-color: #2196F3;
        }

        input:focus+.slider {
            box-shadow: 0 0 1px #2196F3;
        }

        input:checked+.slider:before {
            -webkit-transform: translateX(20px);
            -ms-transform: translateX(20px);
            transform: translateX(20px);
        }

        /* Rounded sliders */
        .slider.round {
            border-radius: 20px;
        }

        .slider.round:before {
            border-radius: 50%;
        }
    </style>
</head>

<body>
    <div style="display: flex; align-items: center; gap: 16px;">
        <img src="favicon-180x180.png" alt="Discogs Cleanup Logo" style="width:48px; height:48px;">
        <h1 style="margin: 0;">Discogs Clean-up</h1>
    </div>
    <?php if (isset($out)) echo $out; ?>
    <hr style="border: none; border-top: 1px solid #d3d3d3; width: 100%; margin: 16px 0 24px 0;">
    <?php
    try {
        if (isset($_GET['act']) && $_GET['act'] == 'all') {
            $stmt = $pdo->query("SELECT id, title, image, format, type, artist, mediaGrading, sleeveGrading, url FROM release ORDER BY title COLLATE NOCASE ASC");
        } else {
            $stmt = $pdo->query("SELECT id, title, image, format, type, artist, mediaGrading, sleeveGrading, url FROM release WHERE mediaGrading IS NULL OR sleeveGrading IS NULL ORDER BY title COLLATE NOCASE ASC");
        }
        $releases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
    ?>

    <div style="margin-bottom: 10px;">
        <span style="font-size: 1.1em;">Show only entries with missing gradings</span>
        <label class="switch">
            <input type="checkbox"
                id="showAllToggle"
                <?php if (!isset($_GET['act']) || (isset($_GET['act']) && $_GET['act'] === 'no')) echo 'checked'; ?>>
            <span class="slider round"></span>

        </label>
    </div>
    <script>
        document.getElementById('showAllToggle').addEventListener('change', function() {
            if (this.checked) {
                window.location.search = '?act=no';
            } else {
                window.location.search = '?act=all';
            }
        });
    </script>

    <div class="results-table">
        <table id="releases" class="display" style="width:100%">
            <thead>
                <tr>
                    <th style="display:none;">ID</th>
                    <th>Thumbnail</th>
                    <th>Title</th>
                    <th>Artist</th>
                    <th>Format</th>
                    <th>Type</th>
                    <th>Media Grading</th>
                    <th>Sleeve Grading</th>
                    <th>URL</th>
                </tr>
                <tr>
                    <th style="display:none;"></th> <!-- ID (hidden) -->
                    <th></th> <!-- Thumbnail, skip filtering -->
                    <th><input type="text" placeholder="Search Title" /></th>
                    <th><input type="text" placeholder="Search Artist" /></th>
                    <th><input type="text" placeholder="Search Format" /></th>
                    <th><input type="text" placeholder="Search Type" /></th>
                    <th><input type="text" placeholder="Search Media Grade" /></th>
                    <th><input type="text" placeholder="Search Sleeve Grade" /></th>
                    <th></th> <!-- URL, skip filtering -->
                </tr>
            </thead>
            <tbody>
                <?php foreach ($releases as $row): ?>
                    <tr>
                        <td style="display:none;"><?php echo htmlspecialchars($row['id']); ?></td>
                        <td>
                            <?php if (file_exists($row['image'])): ?>
                                <img src="<?php echo htmlspecialchars($row['image']); ?>" alt="Cover Image" style="width: 50px; height: 50px;">
                            <?php else: ?>
                                <img src="nocoverart.jpeg" alt="No Cover Art" style="width: 50px; height: 50px;">
                            <?php endif; ?>
                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                        <td><?php echo htmlspecialchars($row['artist']); ?></td>
                        <td><?php echo htmlspecialchars($row['format']); ?></td>
                        <td><?php echo isset($row['type']) ? htmlspecialchars($row['type']) : ''; ?></td>
                        <td><?php echo isset($row['mediaGrading']) ? htmlspecialchars($row['mediaGrading']) : ''; ?></td>
                        <td><?php echo isset($row['sleeveGrading']) ? htmlspecialchars($row['sleeveGrading']) : ''; ?></td>
                        <td>
                            <?php if (!empty($row['url'])): ?>
                                <a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank">Link</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        $(document).ready(function() {
            // Clone header row for filtering
            $('#releases thead tr:eq(1) th').each(function(i) {
                var input = $(this).find('input');
                if (input.length) {
                    input.on('keyup change', function() {
                        if (table.column(i).search() !== this.value) {
                            table.column(i).search(this.value).draw();
                        }
                    });
                }
            });

            var table = $('#releases').DataTable({
                orderCellsTop: true,
                fixedHeader: true,
                "columnDefs": [{
                        "targets": 0,
                        "visible": false
                    },
                    {
                        "targets": [1, 8],
                        "orderable": false,
                        "searchable": false
                    } // Thumbnail and URL: no search
                ],
                "order": [
                    [3, "asc"]
                ]
            });
        });
    </script>
    <div class="built-by">
        <small>Built by <a href="https://neilthompson.me">Neil Thompson</a>. <a href="./?act=recache">#</a></small>
    </div>

</body>

</html>