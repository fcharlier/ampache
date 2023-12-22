<?php

declare(strict_types=0);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright Ampache.org, 2001-2023
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Ampache\Repository\Model;

use Ampache\Module\System\Dba;
use Ampache\Config\AmpConfig;
use Ampache\Repository\ShoutRepositoryInterface;
use Ampache\Repository\UserActivityRepositoryInterface;
use PDOStatement;

class TVShow_Season extends database_object implements
    library_item,
    GarbageCollectibleInterface,
    CatalogItemInterface
{
    protected const DB_TABLENAME = 'tvshow_season';

    /* Variables from DB */
    public int $id = 0;
    public int $season_number;
    public int $tvshow;

    public $catalog_id;
    public $episodes;
    public $f_name;
    public $f_tvshow;
    public $f_tvshow_link;

    public ?string $link = null;

    public $f_link;

    // Constructed vars
    private static $_mapcache = array();

    /**
     * TV Show
     * Takes the ID of the tv show season and pulls the info from the db
     * @param int|null $show_id
     */
    public function __construct($show_id = 0)
    {
        if (!$show_id) {
            return;
        }
        $info = $this->get_info($show_id, static::DB_TABLENAME);
        foreach ($info as $key => $value) {
            $this->$key = $value;
        }
    }

    public function getId(): int
    {
        return (int)($this->id ?? 0);
    }

    public function isNew(): bool
    {
        return $this->getId() === 0;
    }

    /**
     * garbage_collection
     *
     * This cleans out unused tv shows seasons
     */
    public static function garbage_collection(): void
    {
        $sql = "DELETE FROM `tvshow_season` USING `tvshow_season` LEFT JOIN `tvshow_episode` ON `tvshow_episode`.`season` = `tvshow_season`.`id` WHERE `tvshow_episode`.`id` IS NULL";
        Dba::write($sql);
    }

    /**
     * get_songs
     * gets all episodes for this tv show season
     * @return array
     */
    public function get_episodes()
    {
        $sql = (AmpConfig::get('catalog_disable'))
            ? "SELECT `tvshow_episode`.`id` FROM `tvshow_episode` LEFT JOIN `video` ON `video`.`id` = `tvshow_episode`.`id` LEFT JOIN `catalog` ON `catalog`.`id` = `video`.`catalog` WHERE `tvshow_episode`.`season`='" . Dba::escape($this->id) . "' AND `catalog`.`enabled` = '1' "
            : "SELECT `tvshow_episode`.`id` FROM `tvshow_episode` WHERE `tvshow_episode`.`season`='" . Dba::escape($this->id) . "' ";
        $sql .= "ORDER BY `tvshow_episode`.`episode_number`";

        $db_results = Dba::read($sql);
        $results    = array();
        while ($row = Dba::fetch_assoc($db_results)) {
            $results[] = $row['id'];
        }

        return $results;
    }

    /**
     * _get_extra info
     * This returns the extra information for the tv show season, this means totals etc
     * @return array
     */
    private function _get_extra_info()
    {
        // Try to find it in the cache and save ourselves the trouble
        if (parent::is_cached('tvshow_extra', $this->id)) {
            $row = parent::get_from_cache('tvshow_extra', $this->id);
        } else {
            $sql = "SELECT COUNT(`tvshow_episode`.`id`) AS `episode_count`, `video`.`catalog` AS `catalog_id` FROM `tvshow_episode` LEFT JOIN `video` ON `video`.`id` = `tvshow_episode`.`id` WHERE `tvshow_episode`.`season` = ? GROUP BY `catalog_id`";

            $db_results = Dba::read($sql, array($this->id));
            $row        = Dba::fetch_assoc($db_results);
            parent::add_to_cache('tvshow_extra', $this->id, $row);
        }

        /* Set Object Vars */
        $this->episodes   = $row['episode_count'];
        $this->catalog_id = $row['catalog_id'];

        return $row;
    }

    /**
     * format
     * this function takes the object and formats some values
     *
     * @param bool $details
     */
    public function format($details = true): void
    {
        $tvshow = new TvShow($this->tvshow);
        $tvshow->format($details);
        $this->f_tvshow      = $tvshow->get_link();
        $this->f_tvshow_link = $tvshow->f_link;
        $this->get_f_link();

        if ($details) {
            $this->_get_extra_info();
        }
    }

    /**
     * Get item keywords for metadata searches.
     * @return array
     */
    public function get_keywords()
    {
        $keywords           = array();
        $keywords['tvshow'] = array(
            'important' => true,
            'label' => T_('TV Show'),
            'value' => $this->f_tvshow
        );
        $keywords['tvshow_season'] = array(
            'important' => false,
            'label' => T_('Season'),
            'value' => $this->season_number
        );
        $keywords['type'] = array(
            'important' => false,
            'label' => null,
            'value' => 'tvshow'
        );

        return $keywords;
    }

    /**
     * get_fullname
     */
    public function get_fullname(): ?string
    {
        // don't do anything if it's formatted
        if (!isset($this->f_name)) {
            $this->f_name = T_('Season') . ' ' . $this->season_number;
        }

        return $this->f_name;
    }

    /**
     * Get item link.
     */
    public function get_link(): string
    {
        // don't do anything if it's formatted
        if ($this->link === null) {
            $web_path   = AmpConfig::get('web_path');
            $this->link = $web_path . '/tvshow_seasons.php?action=show&season=' . $this->id;
        }

        return $this->link;
    }

    /**
     * Get item f_link.
     */
    public function get_f_link(): string
    {
        // don't do anything if it's formatted
        if (!isset($this->f_link)) {
            $tvshow       = new TvShow($this->tvshow);
            $this->f_link = '<a href="' . $this->get_link() . '" title="' . $tvshow->get_fullname() . ' - ' . scrub_out($this->get_fullname()) . '">' . scrub_out($this->get_fullname()) . '</a>';
        }

        return $this->f_link;
    }

    /**
     * get_parent
     * Return parent `object_type`, `object_id`; null otherwise.
     */
    public function get_parent(): ?array
    {
        return array(
            'object_type' => 'tvshow',
            'object_id' => $this->tvshow
        );
    }

    /**
     * @return array
     */
    public function get_childrens()
    {
        return array('tvshow_episode' => $this->get_episodes());
    }

    /**
     * Search for direct children of an object
     * @param string $name
     * @return array
     */
    public function get_children($name)
    {
        debug_event(self::class, 'get_children ' . $name, 5);

        return array();
    }

    /**
     * get_medias
     * @param string $filter_type
     * @return array
     */
    public function get_medias($filter_type = null)
    {
        $medias = array();
        if ($filter_type === null || $filter_type == 'video') {
            $episodes = $this->get_episodes();
            foreach ($episodes as $episode_id) {
                $medias[] = array(
                    'object_type' => 'video',
                    'object_id' => $episode_id
                );
            }
        }

        return $medias;
    }

    /**
     * Returns the id of the catalog the item is associated to
     */
    public function getCatalogId(): int
    {
        return $this->catalog_id;
    }

    /**
     * @return int|null
     */
    public function get_user_owner(): ?int
    {
        return null;
    }

    public function get_default_art_kind(): string
    {
        return 'default';
    }

    /**
     * get_description
     */
    public function get_description(): string
    {
        // No season description for now, always return tvshow description
        $tvshow = new TvShow($this->tvshow);

        return $tvshow->get_description();
    }

    /**
     * display_art
     * @param int $thumb
     * @param bool $force
     */
    public function display_art($thumb = 2, $force = false): void
    {
        $tvshow_id = null;
        $type      = null;

        if ($this->has_art()) {
            $tvshow_id = $this->id;
            $type      = 'tvshow_season';
        } elseif (Art::has_db($this->tvshow, 'tvshow') || $force) {
            $tvshow_id = $this->tvshow;
            $type      = 'tvshow';
        }

        if ($tvshow_id !== null && $type !== null) {
            Art::display($type, $tvshow_id, (string)$this->get_fullname(), $thumb, $this->get_link());
        }
    }

    public function has_art(): bool
    {
        return Art::has_db($this->id, 'tvshow_season');
    }

    /**
     * check
     *
     * Checks for an existing tv show season; if none exists, insert one.
     * @param $tvshow
     * @param $season_number
     * @param bool $readonly
     * @return int|null
     */
    public static function check($tvshow, $season_number, $readonly = false): ?int
    {
        $name = $tvshow . '_' . $season_number;
        // null because we don't have any unique id like mbid for now
        if (isset(self::$_mapcache[$name]['null'])) {
            return (int)self::$_mapcache[$name]['null'];
        }

        $object_id  = 0;
        $exists     = false;
        $sql        = 'SELECT `id` FROM `tvshow_season` WHERE `tvshow` = ? AND `season_number` = ?';
        $db_results = Dba::read($sql, array($tvshow, $season_number));
        $id_array   = array();
        while ($row = Dba::fetch_assoc($db_results)) {
            $key            = 'null';
            $id_array[$key] = $row['id'];
        }

        if (count($id_array)) {
            $object_id = array_shift($id_array);
            $exists    = true;
        }

        if ($exists && (int)$object_id > 0) {
            self::$_mapcache[$name]['null'] = (int)$object_id;

            return (int)$object_id;
        }

        if ($readonly) {
            return null;
        }

        $sql = 'INSERT INTO `tvshow_season` (`tvshow`, `season_number`) ' . 'VALUES(?, ?)';

        $db_results = Dba::write($sql, array($tvshow, $season_number));
        if (!$db_results) {
            return null;
        }
        $object_id = Dba::insert_id();
        if (!$object_id) {
            return null;
        }

        self::$_mapcache[$name]['null'] = (int)$object_id;

        return (int)$object_id;
    }

    /**
     * update
     * This takes a key'd array of data and updates the current tv show
     * @param array $data
     */
    public function update(array $data): int
    {
        $sql = 'UPDATE `tvshow_season` SET `season_number` = ?, `tvshow` = ? WHERE `id` = ?';
        Dba::write($sql, array($data['season_number'], $data['tvshow'], $this->id));

        return $this->id;
    }

    /**
     * remove
     * Delete the object from disk and/or database where applicable.
     */
    public function remove(): bool
    {
        $deleted = true;
        $videos  = $this->get_episodes();
        foreach ($videos as $video_id) {
            $video   = Video::create_from_id($video_id);
            $deleted = $video->remove();
            if (!$deleted) {
                debug_event(self::class, 'Error when deleting the video `' . $video_id . '`.', 1);
                break;
            }
        }

        if ($deleted) {
            $sql     = "DELETE FROM `tvshow_season` WHERE `id` = ?";
            $deleted = (Dba::write($sql, array($this->id)) !== false);
            if ($deleted) {
                Art::garbage_collection('tvshow_season', $this->id);
                Userflag::garbage_collection('tvshow_season', $this->id);
                Rating::garbage_collection('tvshow_season', $this->id);
                $this->getShoutRepository()->collectGarbage('tvshow_season', $this->getId());
                $this->getUseractivityRepository()->collectGarbage('tvshow_season', $this->getId());
            }
        }

        return $deleted;
    }

    /**
     * @param $tvshow_id
     * @param $season_id
     * @return PDOStatement|bool
     */
    public static function update_tvshow($tvshow_id, $season_id)
    {
        $sql = "UPDATE `tvshow_season` SET `tvshow` = ? WHERE `id` = ?";

        return Dba::write($sql, array($tvshow_id, $season_id));
    }

    /**
     * @deprecated
     */
    private function getShoutRepository(): ShoutRepositoryInterface
    {
        global $dic;

        return $dic->get(ShoutRepositoryInterface::class);
    }

    /**
     * @deprecated
     */
    private function getUseractivityRepository(): UserActivityRepositoryInterface
    {
        global $dic;

        return $dic->get(UserActivityRepositoryInterface::class);
    }
}
