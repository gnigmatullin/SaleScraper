<?php
/**
 * @file google_functions.php
 * @brief Google API functions library
 */

/**
 * @brief Expands the home directory alias '~' to the full path
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path)
{
    $homeDirectory = getenv('HOME');
    if (empty($homeDirectory))
        $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    return str_replace('~', realpath($homeDirectory), $path);
}

/**
 * @brief Returns an authorized API client
 * @return Google_Client the authorized client object
 */
function getClient() {
  $client = new Google_Client();
  $client->setApplicationName(APPLICATION_NAME);
  $client->setScopes(SCOPES);
  $client->setAuthConfig(CLIENT_SECRET_PATH);
  $client->setAccessType('offline');

  // Load previously authorized credentials from a file.
  $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH);
  if (file_exists($credentialsPath)) {
    $accessToken = json_decode(file_get_contents($credentialsPath), true);
  } else {
    // Request authorization from the user.
    $authUrl = $client->createAuthUrl();
    file_put_contents('authUrl.txt', $authUrl);
    printf("Open the following link in your browser:\n%s\n", $authUrl);
    print 'Enter verification code: ';
    $authCode = trim(fgets(STDIN));

    // Exchange authorization code for an access token.
    $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

    // Store the credentials to disk.
    if(!file_exists(dirname($credentialsPath))) {
      mkdir(dirname($credentialsPath), 0700, true);
    }
    file_put_contents($credentialsPath, json_encode($accessToken));
    printf("Credentials saved to %s\n", $credentialsPath);
  }
  $client->setAccessToken($accessToken);

  // Refresh the token if it's expired.
  if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
  }
  return $client;
}

/**
 * @brief Copy an existing file.
 *
 * @param Google_Service_Drive $service Drive API service instance.
 * @param String $originFileId ID of the origin file to copy.
 * @param String $copyName File name of the copy.
 * @return DriveFile The copied file. NULL is returned if an API error occurred.
 */
function copyFile($service, $originFileId, $copyName) {
  $copiedFile = new Google_Service_Drive_DriveFile();
  $copiedFile->setName($copyName);
  try {
    return $service->files->copy($originFileId, $copiedFile);
  } catch (Exception $e) {
    print "An error occurred: " . $e->getMessage();
  }
  return NULL;
}

/**
 * @brief Insert new file.
 *
 * @param Google_Service_Drive $service Drive API service instance.
 * @param string $title Title of the file to insert, including the extension.
 * @param string $description Description of the file to insert.
 * @param string $parentId Parent folder's ID.
 * @param string $mimeType MIME type of the file to insert.
 * @param string $filename Filename of the file to insert.
 * @return Google_Service_Drive_DriveFile The file that was inserted. NULL is
 *     returned if an API error occurred.
 */
function insertFile($service, $title, $description, $parentId, $mimeType, $filename) {
  $file = new Google_Service_Drive_DriveFile();
  $file->setParents(array($parentId));
  $file->setName($title);

  try {
    $data = file_get_contents($filename);

    $createdFile = $service->files->create($file, array(
      'data' => $data,
      'mimeType' => $mimeType,
    ));

    return $createdFile;
  } catch (Exception $e) {
    print "An error occurred: " . $e->getMessage();
  }
}

/**
 * @brief Search file
 *
 * @param Google_Service_Drive $service Drive API service instance.
 * @param string $filename Filename of the file to search.
 * @return string file ID if file was found, false otherwise.
 */
function searchFile($service, $fileName) {
    $pageToken = null;
    do {
        $response = $service->files->listFiles(array(
            'q' => 'name="'.$fileName.'" and trashed=false',
            'spaces' => 'drive',
            'pageToken' => $pageToken,
            'fields' => 'nextPageToken, files(id, name)',
        ));
        foreach ($response->files as $file) {
            return $file->id;
        }
        $pageToken = $response->pageToken;
    } while ($pageToken != null);
    return false;
}
