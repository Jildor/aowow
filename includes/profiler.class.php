<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


class Profiler
{
    private static $realms  = [];
    private static $pidFile = 'config/pr-queue-pid';

    public static function getBuyoutForItem($itemId)
    {
        if (!$itemId)
            return 0;

        // try, when having filled char-DB at hand
        // return DB::Characters()->selectCell('SELECT SUM(a.buyoutprice) / SUM(ii.count) FROM auctionhouse a JOIN item_instance ii ON ii.guid = a.itemguid WHERE ii.itemEntry = ?d', $itemId);
        return 0;
    }

    public static function queueStatus()
    {
        if (!file_exists(self::$pidFile))
            return 0;

        $pid = intval(file_get_contents(self::$pidFile));

        exec('ps --no-headers p '.$pid, $out);
        if ($out)
            return $pid;

        // have pidFile but no process with this pid
        self::queueFree();
        return 0;
    }

    public static function queueLock($pid)
    {
        $queuePID = self::queueStatus();
        if ($queuePID && $queuePID != $pid)
        {
            trigger_error('pSync - another queue with PID #'.$queuePID.' is already running', E_USER_ERROR);
            CLI::write('Profiler::queueLock() - another queue with PID #'.$queuePID.' is already runnung', CLI::LOG_ERROR);
            return false;
        }

        // no queue running; create or overwrite pidFile
        $ok = false;
        if ($fh = fopen(self::$pidFile, 'w'))
        {
            if (fwrite($fh, $pid))
                $ok = true;

            fclose($fh);
        }

        return $ok;
    }

    public static function queueFree()
    {
        unlink(self::$pidFile);
    }

    public static function urlize($str)
    {
        $search  = ['<', '>', ' / ', "'", '(', ')'];
        $replace = ['&lt;', '&gt;', '-', '', '', ''];
        $str = str_replace($search, $replace, $str);

        $accents = array(
            "ß" => "ss",
            "á" => "a", "ä" => "a", "à" => "a", "â" => "a",
            "è" => "e", "ê" => "e", "é" => "e", "ë" => "e",
            "í" => "i", "î" => "i", "ì" => "i", "ï" => "i",
            "ñ" => "n",
            "ò" => "o", "ó" => "o", "ö" => "o", "ô" => "o",
            "ú" => "u", "ü" => "u", "û" => "u", "ù" => "u",
            "œ" => "oe",
            "Á" => "A", "Ä" => "A", "À" => "A", "Â" => "A",
            "È" => "E", "Ê" => "E", "É" => "E", "Ë" => "E",
            "Í" => "I", "Î" => "I", "Ì" => "I", "Ï" => "I",
            "Ñ" => "N",
            "Ò" => "O", "Ó" => "O", "Ö" => "O", "Ô" => "O",
            "Ú" => "U", "Ü" => "U", "Û" => "U", "Ù" => "U",
            "œ" => "Oe"
        );
        $str = strtr($str, $accents);
        $str = trim($str);
        $str = preg_replace('/[^a-z0-9]/i', '-', $str);

        $str = str_replace('--', '-', $str);
        $str = str_replace('--', '-', $str);

        $str = rtrim($str, '-');
        $str = strtolower($str);

        return $str;
    }

    public static function getRealms()
    {
        if (DB::isConnectable(DB_AUTH) && !self::$realms)
        {
            self::$realms = DB::Auth()->select('SELECT id AS ARRAY_KEY, name, IF(timezone IN (8, 9, 10, 11, 12), "eu", "us") AS region FROM realmlist WHERE allowedSecurityLevel = 0 AND gamebuild = ?d', WOW_BUILD);
            foreach (self::$realms as $rId => $rData)
            {
                if (DB::isConnectable(DB_CHARACTERS . $rId))
                    continue;

                unset(self::$realms[$rId]);
                // maybe remove; can get annoying
                // trigger_error('Realm #'.$rId.' ('.$rData['name'].') has no connection info set.', E_USER_NOTICE);
            }
        }

        return self::$realms;
    }

    public static function scheduleResync($type, $realmId, $guid)
    {
        $newId = 0;

        switch ($type)
        {
            case TYPE_PROFILE:
                DB::Aowow()->query('INSERT IGNORE INTO ?_profiler_profiles (realm, realmGUID) VALUES (?d, ?d)', $realmId, $guid);
                $newId = DB::Aowow()->selectCell('SELECT id FROM ?_profiler_profiles WHERE realm = ?d AND realmGUID = ?d', $realmId, $guid);

                if ($rData = DB::Aowow()->selectRow('SELECT requestTime AS time, status FROM ?_profiler_sync WHERE realm = ?d AND realmGUID = ?d AND `type` = ?d AND typeId = ?d AND status <> ?d', $realmId, $guid, $type, $newId, PR_QUEUE_STATUS_WORKING))
                {
                    // not on already scheduled - recalc time and set status to PR_QUEUE_STATUS_WAITING
                    if ($rData['status'] != PR_QUEUE_STATUS_WAITING)
                    {
                        $newTime = max($rData['time'] + CFG_PROFILER_RESYNC_DELAY, time());
                        DB::Aowow()->query('UPDATE ?_profiler_sync SET requestTime = ?d, status = ?d, errorCode = 0 WHERE realm = ?d AND realmGUID = ?d AND `type` = ?d AND typeId = ?d', $newTime, PR_QUEUE_STATUS_WAITING, $realmId, $guid, $type, $newId);
                    }
                }
                else
                    DB::Aowow()->query('REPLACE INTO ?_profiler_sync (realm, realmGUID, `type`, typeId, requestTime, status, errorCode) VALUES (?d, ?d, ?d, ?d, UNIX_TIMESTAMP(), ?d, 0)', $realmId, $guid, $type, $newId, PR_QUEUE_STATUS_WAITING);

                break;
            case TYPE_GUILD:

                break;
            case TYPE_ARENA_TEAM:

                break;
            default:
                trigger_error('scheduling resync for unknown type #'.$type.' omiting..', E_USER_WARNING);
        }

        return $newId;
    }

    public static function getCharFromRealm($realmId, $charGuid)
    {
        $tDiffs = [];

        $char = DB::Characters($realmId)->selectRow('SELECT * FROM characters WHERE guid = ?d', $charGuid);
        if (!$char)
            return false;

        CLI::write('fetching char #'.$charGuid.' from realm #'.$realmId);
        CLI::write('writing...');

        /**************/
        /* basic info */
        /**************/

        $data = array(
            'realm'       =>  $realmId,
            'realmGUID'   =>  $char['guid'],
            'name'        =>  $char['name'],
            'race'        =>  $char['race'],
            'class'       =>  $char['class'],
            'level'       =>  $char['level'],
            'gender'      =>  $char['gender'],
            'skincolor'   =>  $char['playerBytes']        & 0xFF,
            'facetype'    => ($char['playerBytes'] >>  8) & 0xFF, // maybe features
            'hairstyle'   => ($char['playerBytes'] >> 16) & 0xFF,
            'haircolor'   => ($char['playerBytes'] >> 24) & 0xFF,
            'features'    =>  $char['playerBytes2']       & 0xFF, // maybe facetype
            'title'       =>  $char['chosenTitle'] ? DB::Aowow()->selectCell('SELECT id FROM ?_titles WHERE bitIdx = ?d', $char['chosenTitle']) : 0,
            'playedtime'  =>  $char['totaltime'],
            'nomodelMask' => ($char['playerFlags'] & 0x400 ? (1 << SLOT_HEAD) : 0) | ($char['playerFlags'] & 0x800 ? (1 << SLOT_BACK) : 0),
            'spec1'       => [],                    // space separated - tree1 tree2 tree3 glyph1 glyph2 glyph3 glyph4 glyph5 glyph6
            'spec2'       => [],
            'activespec'  =>  $char['activespec'],
        );

        // talents + glyphs
        $t = DB::Characters($realmId)->selectCol('SELECT spec AS ARRAY_KEY, spell AS ARRAY_KEY2, spell FROM character_talent WHERE guid = ?d', $char['guid']);
        $g = DB::Characters($realmId)->select('SELECT spec AS ARRAY_KEY, glyph1 AS g1, glyph2 AS g4, glyph3 AS g5, glyph4 AS g2, glyph5 AS g3, glyph6 AS g6 FROM character_glyphs WHERE guid = ?d', $char['guid']);
        for ($i = 0; $i < 2; $i++)
        {
            // talents
            for ($j = 0; $j < 3; $j++)
            {
                $_ = DB::Aowow()->selectCol('SELECT spell AS ARRAY_KEY, MAX(IF(spell in (?a), rank, 0)) FROM ?_talents WHERE class = ?d AND tab = ?d GROUP BY id ORDER BY row, col ASC', !empty($t[$i]) ? $t[$i] : [0], $char['class'], $j);
                $data['spec'.($i + 1)][$j] = implode('', $_);
            }

            // glyphs
            if (isset($g[$i]))
            {
                $gProps = [];
                for ($j = 1; $j <= 6; $j++)
                    if ($g[$i]['g'.$j])
                        $gProps[$j] = $g[$i]['g'.$j];

                if ($gProps)
                    $gItems = DB::Aowow()->selectCol('SELECT i.id, gp.id AS ARRAY_KEY FROM ?_glyphproperties gp JOIN ?_spell s ON s.effect1MiscValue = gp.id AND s.effect1Id = 74 JOIN ?_items i ON i.class = 16 AND i.spellId1 = s.id WHERE gp.id IN (?a)', $gProps);

                for ($j = 1; $j <= 6; $j++)
                    $data['spec'.($i + 1)][$j + 2] = !empty($gProps[$j]) && !empty($gItems[$gProps[$j]]) ? $gItems[$gProps[$j]] : 0;

            }
            else
                array_push($data['spec'.($i + 1)], 0, 0, 0, 0, 0, 0);

            $data['spec'.($i + 1)] = implode(' ', $data['spec'.($i + 1)]);
        }

        DB::Aowow()->query('INSERT INTO ?_profiler_profiles (?#) VALUES (?a) ON DUPLICATE KEY UPDATE ?a', array_keys($data), array_values($data), $data);
        $charGuid = DB::Aowow()->selectCell('SELECT id FROM ?_profiler_profiles WHERE realm = ?d AND realmGUID = ?d', $realmId, $char['guid']);

        CLI::write(' ..basic info');

        // equipment
        /* enchantment-Indizes
         *  0: permEnchant
         *  3: tempEnchant
         *  6: gem1
         *  9: gem2
         * 12: gem3
         * 15: socketBonus [not used]
         * 18: extraSocket [only check existance]
         * 21 - 30: randomProp enchantments
         */
        $items = DB::Characters($realmId)->select('SELECT ci.slot AS ARRAY_KEY, ii.itemEntry, ii.enchantments, ii.randomPropertyId FROM character_inventory ci JOIN item_instance ii ON ci.item = ii.guid WHERE ci.guid = ?d AND bag = 0 AND slot BETWEEN 0 AND 18', $char['guid']);
        foreach ($items as $slot => $item)
        {
            $ench   = explode(' ', $item['enchantments']);
            $gEnch  = [];
            $gitems = [];
            foreach ([6, 9, 12] as $idx)
                if ($ench[$idx])
                    $gEnch[$idx] = $ench[$idx];

            if ($gEnch)
                $gItems = DB::Aowow()->selectCol('SELECT gemEnchantmentId AS ARRAY_KEY, id FROM ?_items WHERE class = 3 AND gemEnchantmentId IN (?a)', $gEnch);

            $data = array(
                'id'          => $charGuid,
                'slot'        => $slot + 1,
                'item'        => $item['itemEntry'],
                'subItem'     => $item['randomPropertyId'],
                'permEnchant' => $ench[0],
                'tempEnchant' => $ench[3],
                'extraSocket' => (int)!!$ench[18],
                'gem1'        => isset($gItems[$ench[6]])  ? $gItems[$ench[6]]  : 0,
                'gem2'        => isset($gItems[$ench[9]])  ? $gItems[$ench[9]]  : 0,
                'gem3'        => isset($gItems[$ench[12]]) ? $gItems[$ench[12]] : 0,
                'gem4'        => 0                  // not used, items can have a max of 3 sockets (including extraSockets) but is expected by js
            );

            DB::Aowow()->query('REPLACE INTO ?_profiler_items (?#) VALUES (?a)', array_keys($data), array_values($data));
        }

        CLI::write(' ..inventory');


        /*******************/
        /* completion data */
        /*******************/

        // done quests
        $quests = DB::Characters($realmId)->select('SELECT ?d AS id, ?d AS `type`, quest AS typeId FROM character_queststatus_rewarded WHERE guid = ?d', $charGuid, TYPE_QUEST, $char['guid']);
        DB::Aowow()->query('DELETE FROM ?_profiler_completion WHERE `type` = ?d AND id = ?d', TYPE_QUEST, $charGuid);
        foreach ($quests as $q)
            DB::Aowow()->query('INSERT INTO ?_profiler_completion (?#) VALUES (?a)', array_keys($q), array_values($q));

        CLI::write(' ..quests');


        // known skills (professions only)
        $skAllowed = DB::Aowow()->selectCol('SELECT id FROM ?_skillline WHERE typeCat IN (9, 11) AND (cuFlags & ?d) = 0', CUSTOM_EXCLUDE_FOR_LISTVIEW);
        $skills = DB::Characters($realmId)->select('SELECT ?d AS id, ?d AS `type`, skill AS typeId, `value` AS cur, max FROM character_skills WHERE guid = ?d AND skill IN (?a)', $charGuid, TYPE_SKILL, $char['guid'], $skAllowed);
        DB::Aowow()->query('DELETE FROM ?_profiler_completion WHERE `type` = ?d AND id = ?d', TYPE_SKILL, $charGuid);
        foreach ($skills as $sk)
            DB::Aowow()->query('INSERT INTO ?_profiler_completion (?#) VALUES (?a)', array_keys($sk), array_values($sk));

        CLI::write(' ..professions');


        // reputation
        $reputation = DB::Characters($realmId)->select('SELECT ?d AS id, ?d AS `type`, faction AS typeId, standing AS cur FROM character_reputation WHERE guid = ?d AND (flags & 0xC) = 0', $charGuid, TYPE_FACTION, $char['guid']);
        DB::Aowow()->query('DELETE FROM ?_profiler_completion WHERE `type` = ?d AND id = ?d', TYPE_FACTION, $charGuid);
        foreach ($reputation as $rep)
            DB::Aowow()->query('INSERT INTO ?_profiler_completion (?#) VALUES (?a)', array_keys($rep), array_values($rep));

        CLI::write(' ..reputation');


        // known titles
        $tBlocks = explode(' ', $char['knownTitles']);
        $indizes = [];
        for ($i = 0; $i < 6; $i++)
            for ($j = 0; $j < 32; $j++)
                if ($tBlocks[$i] & (1 << $j))
                    $indizes[] = $j + ($i * 32);

        DB::Aowow()->query('DELETE FROM ?_profiler_completion WHERE `type` = ?d AND id = ?d', TYPE_TITLE, $charGuid);
        if ($indizes)
            DB::Aowow()->query('INSERT INTO ?_profiler_completion SELECT ?d, ?d, id, NULL, NULL FROM ?_titles WHERE bitIdx IN (?a)', $charGuid, TYPE_TITLE, $indizes);

        CLI::write(' ..titles');


        // achievements
        $achievements = DB::Characters($realmId)->select('SELECT ?d AS id, ?d AS `type`, achievement AS typeId, date AS cur FROM character_achievement WHERE guid = ?d', $charGuid, TYPE_ACHIEVEMENT, $char['guid']);
        DB::Aowow()->query('DELETE FROM ?_profiler_completion WHERE `type` = ?d AND id = ?d', TYPE_ACHIEVEMENT, $charGuid);
        foreach ($achievements as $a)
            DB::Aowow()->query('INSERT INTO ?_profiler_completion (?#) VALUES (?a)', array_keys($a), array_values($a));

        CLI::write(' ..achievements');


        // known spells
        $spells = DB::Characters($realmId)->select('SELECT ?d AS id, ?d AS `type`, spell AS typeId FROM character_spell WHERE guid = ?d AND disabled = 0', $charGuid, TYPE_SPELL, $char['guid']);
        DB::Aowow()->query('DELETE FROM ?_profiler_completion WHERE `type` = ?d AND id = ?d', TYPE_SPELL, $charGuid);
        foreach ($spells as $s)
            DB::Aowow()->query('INSERT INTO ?_profiler_completion (?#) VALUES (?a)', array_keys($s), array_values($s));

        CLI::write(' ..known spells (vanity pets & mounts)');


        /****************/
        /* related data */
        /****************/

        // pets (hunter)

        // guilds

        // arena teams

        return true;
    }
}

?>
