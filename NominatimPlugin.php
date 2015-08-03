<?php

if (!defined('GNUSOCIAL')) {
    exit(1);
}

class NominatimPlugin extends Plugin
{
    // namespace
    const LOCATION_NS = 2;

    // plugin version
    const VERSION = '0.2.0';

    // how long do we keep the information in the cache (in seconds)
    public $expiry   = 60 * 60 * 24 * 90; // 90-days

    // how long do we wait for an answer from the remote service (in seconds)
    public $timeout  = 2;

    // how long do we back off after the service timed out (in seconds)
    public $timeoutWindow = 60;

    // when was the last time the service timed out (timestamp)
    protected $lastTimeout = null;

    /**
     * Get values from config.php or fallback to defaults if they don't exist
     */
    function initialize()
    {
        $this->host = common_config('nominatim', 'host') ?: 'open.mapquestapi.com/nominatim/v1';
        $this->credits = common_config('nominatim', 'credits') ?: '<p>Nominatim Search Courtesy of <a href="http://www.mapquest.com/">MapQuest</a></p>';
    }

    /**
     * Add OSM and Nominatim license information to the instance's
     * "license" section (usually in the footer)
     */
    function onEndShowContentLicense($action)
    {
        $action->elementStart('div', array('class' => 'nominatim-credits'));

        // Nominatim service license
        $action->raw($this->credits);

        // OSM license (ODbL)
        $action->raw('<p>OpenStreetMap data is licensed under the <a href="http://opendatacommons.org/licenses/odbl/">Open Data Commons Open Database License (ODbL).</a></p>');
        $action->elementEnd('div');
    }

    /**
     * convert a name into a Location object
     *
     * @param string   $name      Name to convert
     * @param string   $language  ISO code for language the name is in
     * @param Location &$location Location object (may be null)
     *
     * @return boolean whether to continue (results in $location)
     */
    function onLocationFromName($name, $language, &$location)
    {
        $params = array(
            'name' => $name,
            'language' => $language
        );

        $loc = $this->getCache($params);

        if ($loc !== false) {
            $location = $loc;
            return false;
        }

        try {
            $geonames = $this->getGeonames('search',
                                           array('limit' => 1,
                                                 'q' => $name,
                                                 'accept-language' => $language,
                                                 'format' => 'xml'));
        } catch (Exception $e) {
            $this->log(LOG_DEBUG, "Error for $name: " . $e->getMessage());
            return true;
        }

        // no results
        if (count($geonames) === 0) {
            $this->setCache($params, null);
            return true;
        }

        $location = new Location();

        $location->location_id = (string)$geonames->place['osm_id'];
        $location->location_ns = self::LOCATION_NS;
        $location->lat         = $this->canonical((string)$geonames->place->attributes()['lat']);
        $location->lon         = $this->canonical((string)$geonames->place->attributes()['lon']);
        $location->names[$language] = (string)$geonames->place['display_name'];

        $this->setCache($params, $location);

        return false;
    }

    /**
     * convert an id into a Location object
     *
     * @param string   $id        Name to convert
     * @param string   $ns        Name to convert
     * @param string   $language  ISO code for language for results
     * @param Location &$location Location object (may be null)
     *
     * @return boolean whether to continue (results in $location)
     */
    function onLocationFromId($id, $ns, $language, &$location)
    {
        if ($ns != self::LOCATION_NS) {
            // It's not one of our IDs... keep processing
            return true;
        }

        $loc = $this->getCache(array('id' => $id));

        if ($loc !== false) {
            $location = $loc;
            return false;
        }

        try {
            $geonames = $this->getGeonames('reverse',
                                           array('osm_type' => 'N', // FIXME: https://github.com/chimo/gs-nominatim/issues/5
                                                 'osm_id' => $id));
        } catch (Exception $e) {
            $this->log(LOG_DEBUG, "Error for ID $id: " . $e->getMessage());
            return false;
        }

        $location = new Location();

        $location->location_id = (string)$geonames->result['osm_id'];
        $location->location_ns = self::LOCATION_NS;
        $location->lat         = $this->canonical((string)$geonames->result->attributes()['lat']);
        $location->lon         = $this->canonical((string)$geonames->result->attributes()['lon']);

        $parts = $this->getAddressParts($geonames->addressparts);
        $location->names[$language] = implode(', ', $parts);

        $this->setCache(array('id' => (string)$geonames->result['osm_id']),
                        $location);

        // We're responsible for this namespace; nobody else
        // can resolve it

        return false;
    }

    /**
     * Return an array of address parts we're interested in
     * (town, city, state, country...)
     */
    function getAddressParts($n) {
        $parts = array();

        if (!empty($n->town)) {
            $parts[] = (string)$n->town;
        }

        if (!empty($n->city)) {
            $parts[] = (string)$n->city;
        }

        if (!empty($n->state)) {
            $parts[] = (string)$n->state;
        }

        if (!empty($n->country)) {
            $parts[] = (string)$n->country;
        }

        return $parts;
    }

    /**
     * convert a lat/lon pair into a Location object
     *
     * Given a lat/lon, we try to find a Location that's around
     * it or nearby. We prefer populated places (cities, towns, villages).
     *
     * @param string   $lat       Latitude
     * @param string   $lon       Longitude
     * @param string   $language  ISO code for language for results
     * @param Location &$location Location object (may be null)
     *
     * @return boolean whether to continue (results in $location)
     */
    function onLocationFromLatLon($lat, $lon, $language, &$location)
    {
        // Make sure they're canonical
        $lat = $this->canonical($lat);
        $lon = $this->canonical($lon);

        $loc = $this->getCache(array('lat' => $lat,
                                     'lon' => $lon));

        if ($loc !== false) {
            $location = $loc;
            return false;
        }

        try {
          $geonames = $this->getGeonames('reverse',
                                         array('lat' => $lat,
                                               'lon' => $lon,
                                               'accept-language' => $language));
        } catch (Exception $e) {
            $this->log(LOG_DEBUG, "Error for coords $lat, $lon: " . $e->getMessage());
            return true;
        }

        if (count($geonames) == 0) {
            // no results
            $this->setCache(array('lat' => $lat,
                                  'lon' => $lon),
                            null);
            return true;
        }

        $location = new Location();

        $location->location_id = (string)$geonames->result['osm_id'];
        $location->location_ns = self::LOCATION_NS;
        $location->lat         = $this->canonical((string)$geonames->result->attributes()['lat']);
        $location->lon         = $this->canonical((string)$geonames->result->attributes()['lon']);

        $parts = $this->getAddressParts($geonames->addressparts);
        $location->names[$language] = implode(', ', $parts);

        $this->setCache(array('lat' => $lat,
                              'lon' => $lon),
                        $location);

        // Success! We handled it, so no further processing
        return false;
    }

    /**
     * Human-readable URL for a location
     *
     * Given a location, we try to retrieve a geonames.org URL.
     *
     * @param Location $location Location to get the url for
     * @param string   &$url     Place to put the url
     *
     * @return boolean whether to continue
     */
    function onLocationUrl($location, &$url)
    {
        if ($location->location_ns != self::LOCATION_NS) {
            // It's not one of our IDs... keep processing
            return true;
        }

        $url = 'http://www.openstreetmap.org/?mlat=' . $location->lat . '&mlon=' . $location->lon;

        // it's been filled, so don't process further.
        return false;
    }

    /**
     * Machine-readable name for a location
     *
     * Given a location, we try to retrieve a geonames.org URL.
     *
     * @param Location $location Location to get the url for
     * @param string   &$url     Place to put the url
     *
     * @return boolean whether to continue
     */
    function onLocationRdfUrl($location, &$url)
    {
        if ($location->location_ns != self::LOCATION_NS) {
            // It's not one of our IDs... keep processing
            return true;
        }

        $url = 'http://linkedgeodata.org/data/triplify/node' . $location->location_id . '?output=xml';

        // it's been filled, so don't process further.
        return false;
    }

    /**
     * Retrieve a location from cache
     */
    function getCache($attrs)
    {
        $c = Cache::instance();

        if (empty($c)) {
            return null;
        }

        $key = $this->cacheKey($attrs);

        $value = $c->get($key);

        return $value;
    }

    /**
     * Insert a location in cache
     */
    function setCache($attrs, $loc)
    {
        $c = Cache::instance();

        if (empty($c)) {
            return null;
        }

        $key = $this->cacheKey($attrs);

        $result = $c->set($key, $loc, 0, time() + $this->expiry);

        return $result;
    }

    /**
     * Build cache key
     */
    function cacheKey($attrs)
    {
        $key = 'nominatim:' .
               implode(',', array_keys($attrs)) . ':'.
               Cache::keyize(implode(',', array_values($attrs)));

        return Cache::key($key);
    }

    /**
     * Build the URL to the nominatim service
     */
    function nomUrl($method, $params)
    {
        $query = http_build_query($params, null, '&');

        return 'http://' . $this->host . '/' . $method . '?' . $query;
    }

    /**
     * Make requests to the nominatim service
     */
    function getGeonames($method, $params)
    {
        if ($this->lastTimeout && (time() - $this->lastTimeout < $this->timeoutWindow)) {
            // TRANS: Exception thrown when a geo names service is not used because of a recent timeout.
            throw new Exception(_m('Skipping due to recent web service timeout.'));
        }

        $client = HTTPClient::start();
        $client->setConfig('connect_timeout', $this->timeout);
        $client->setConfig('timeout', $this->timeout);
        $client->setHeader('User-Agent', 'GNU social Nominatim Plugin - https://github.com/chimo/gs-nominatim');

        try {
            $result = $client->get($this->nomUrl($method, $params));
        } catch (Exception $e) {
            common_log(LOG_ERR, __METHOD__ . ": " . $e->getMessage());
            $this->lastTimeout = time();
            throw $e;
        }

        if (!$result->isOk()) {
            // TRANS: Exception thrown when a geo names service does not return an expected response.
            // TRANS: %s is an HTTP error code.
            throw new Exception(sprintf(_m('HTTP error code %s.'), $result->getStatus()));
        }

        $body = $result->getBody();

        if (empty($body)) {
            // TRANS: Exception thrown when a geo names service returns an empty body.
            throw new Exception(_m('Empty HTTP body in response.'));
        }

        // This will throw an exception if the XML is mal-formed
        $document = new SimpleXMLElement($body);

        // No children, usually no results
        $children = $document->children();

        if (count($children) == 0) {
            return array();
        }

        if (isset($document->error)) {
            throw new Exception("Location service returned an error: " . $document->error);
        }

        return $document;
    }

    /**
     * Plugin info (appears on /main/version page)
     */
    function onPluginVersion(&$versions)
    {
        $versions[] = array('name' => 'Nominatim',
                            'version' => self::VERSION,
                            'author' => 'Stephane Berube',
                            'homepage' => 'https://github.com/chimo/gs-nominatim',
                            'rawdescription' =>
                            // TRANS: Plugin description.
                            _m('Uses <a href="http://nominatim.openstreetmap.org/">Nominatim</a> service to get human-readable '.
                               'names for locations based on user-provided lat/long pairs.'));
        return true;
    }

    /**
     * Remove trailing zeroes, and then trailing periods from a lat or lon
     */
    function canonical($coord)
    {
        $coord = rtrim($coord, "0");
        $coord = rtrim($coord, ".");

        return $coord;
    }
}
