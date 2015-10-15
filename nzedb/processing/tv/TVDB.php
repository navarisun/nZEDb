<?php
namespace nzedb\processing\tv;

use nzedb\utility\Misc;
use libs\Moinax\TVDB\Client;

/**
 * Class TVDB
 */
class TVDB extends TV
{
	const TVDB_URL = 'http://thetvdb.com';
	const TVDB_API_KEY = '5296B37AEC35913D';
	const MATCH_PROBABILITY = 75;

	/**
	 * @var \libs\Moinax\TVDB\Client
	 */
	public $client;

	/**
	 * @param array $options Class instances / Echo to cli?
	 */
	public function __construct(array $options = [])
	{
		parent::__construct($options);
		$this->client = new Client(self::TVDB_URL, self::TVDB_API_KEY);
		$this->posterUrl = self::TVDB_URL . DS . 'banners/_cache/posters/%s-1.jpg';
		$this->imgSavePath = nZEDb_COVERS . 'tvdb' . DS;
	}

	public function processTVDB ($groupID, $guidChar, $processTV, $local = false)
	{
		$res = $this->getTvReleases($groupID, $guidChar, $lookupSetting, $local, parent::PROCESS_TVDB);

		$tvcount = count($res);

		if ($this->echooutput && $tvcount > 1) {
			echo $this->pdo->log->header("Processing TVDB lookup for " . number_format($tvcount) . " release(s).");
		}

		if ($res instanceof \Traversable) {
			foreach ($res as $row) {

				$tvdbid = $video = false;

				// Clean the show name for better match probability
				$release = $this->parseNameEpSeason($row['searchname']);

				if (is_array($release) && $release['name'] != '') {

					// Find the Video ID if it already exists by checking the title.
					$video = $this->getByTitle($release['cleanname']);

					if ($video != false) {
						$tvdbid = $this->getSiteByID('tvdb', $video);
					}

					// Force local lookup only
					if ($local == true) {
						$lookupSetting = false;
					}

					if ($tvdbid == false && $lookupSetting) {

						// If it doesnt exist locally and lookups are allowed lets try to get it.
						if ($this->echooutput) {
							echo     $this->pdo->log->primaryOver("Video ID for ") .
								 $this->pdo->log->headerOver($release['cleanname']) .
								 $this->pdo->log->primary(" not found in local db, checking web.");
						}

						// Get the show from TVDB
						$tvdbShow = $this->getTVDBShow($release);

						if ($tvdbShow !== false && is_array($tvdbShow)) {

							$tvdbShow['hascover'] = getTVDBPoster($tvdbShow);

							$video = $this->add(
										'tvdb',
										$tvdbShow['siteid'],
										$tvdbShow['title'],
										$tvdbShow['summary'],
										$tvdbShow['country'],
										$tvdbShow['started'],
										$tvdbShow['publisher'],
										$tvdbShow['hascover'],
										parent::SOURCE_TVDB
							);
							$tvdbid = $tvdbShow['siteid'];
						}
					}					

					if (is_numeric($video) && $video > 0 && is_numeric($tvdbid) && $tvdbid > 0) {

						// Check first if we have the episode for this video
						$episode = $this->getBySeasonEp($video, $release['season'], $release['episode']);

						if ($episode === false && $lookupSetting) {

							$episode = $this->getTVDBEpisode($tvdbid, $release['season'], $release['episode']);

							if (is_array($episode)) {
								$this->addEpisode(
											$video,
											$release['season'],
											$release['episode'],
											'S' . $release['season'] . 'E' . $release['episode'],
											$episode['title'],
											$episode['firstaired'],
											$episode['summary']
								);
							}
						}
						if (is_numeric($episode) && $episode > 0) {
							$this->setVideoIdFound($video, $row['id'], $episode);
						}
					} else {
						$this->setVideoNotFound(parent::PROCESS_TRAKT, $row['id']);
					}
				}
			}
		}
	}

	private function getTVDBShow($release)
	{
		$return = false;
		$highestMatch = 0;
		$response = $this->client->getSeries($release['searchname']);

		if ($response instanceof Traversable) {
			foreach ($response as $show) {

				// Check for exact title match first and then terminate if found
				if ($show['name'] === $release['searchname']) {
					$return = show;
					break;
				}

				// Check each show title for similarity and then find the highest similar value
				$matchProb = similartext($show['name'], $release['searchname']);
				if ($matchProb >= self::MATCH_PROBABILITY) {
					if ($matchProb > $highestMatch) {
						$highestMatch = $matchProb;
						$return = $show;
					}
				}
			}
			$return = $this->formatShowArr($return);
		}
		return $return;
	}

	private function getTVDBPoster($show)
	{
		return (new ReleaseImage($this->pdo))->saveImage($show['siteid'], $show['imgurl'], $this->imgSavePath, '', '');
	}

	private function getTVDBEpisode($tvdbid, $season, $episode)
	{
		$return = false;
		$response = $this->client->getEpisode($tvbdid, $season, $episode);

		if ($response instanceof Traversable) {
			$return = formatEpisodeArr($response);
		}
		return $return;
	}

	private function formatShowArr($show)
	{
		return	[
				'column'    => (string)'tvdb',
				'siteid'    => (int)$show['id'],
				'title'     => (string)$show['name'],
				'summary'   => (string)$show['overview'],
				'country'   => (string)'',
				'started'   => (string)date('m-d-Y', strtotime($show['firstAired']['date'])),
				'publisher' => (string)$show['network'],
				'imgurl'    => (string)sprintf($this->posterUrl, $show['id'])
			];
	}

	private function formatEpisodeArr($episode)
	{
		return	[
					'title'      => $response['name'],
					'firstaired' => date('m-d-Y', strtotime($response['firstAired']['date'])),
					'summary'    => $response['overview']
				];
	}
}
