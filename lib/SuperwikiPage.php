<?php

class SuperwikiPage extends SimpleORMap {

    static public function findByName($name, $seminar_id)
    {
        $pages = self::findBySQL("name = :name AND seminar_id = :seminar_id", array('name' => $name, 'seminar_id' => $seminar_id));
        return count($pages) ? $pages[0] : null;
    }

    static public function findAll($seminar_id)
    {
        return self::findBySQL("content != '' AND content IS NOT NULL AND seminar_id = ? ORDER BY name ASC", array($seminar_id));
    }

    protected static function configure($config = [])
    {
        $config['db_table'] = 'superwiki_pages';
        $config['has_many']['versions'] = [
            'class_name' => 'SuperwikiVersion',
            'on_delete' => 'delete',
            'on_store' => 'store'
        ];
        $config['belongs_to']['settings'] = [
            'class_name' => 'SuperwikiSettings',
            'foreign_key' => 'seminar_id'
        ];
        $config['registered_callbacks']['before_store'][] = 'createVersion';
        parent::configure($config);
    }

    protected function createVersion()
    {
        if (
            !$this->isNew()
            && $this->content['content'] !== $this->content_db['content']
            && (
                $this->content_db['last_author'] !== $this->content['last_author']
                || $this['chdate'] < time() - 60 * 30
            )
        ) {
            //Neue Version anlegen:
            $version = new SuperwikiVersion();
            $version->setData($this->content_db);
            $version->setId($version->getNewId());
            $version->store();
        }
        $this['last_author'] = $GLOBALS['user']->id;
        return true;
    }

    public function isReadable($user_id = null)
    {
        $user_id || $user_id = $GLOBALS['user']->id;
        if (!$user_id === 'cms' && !$GLOBALS['perm']->have_studip_perm("user", $this['seminar_id'], $user_id)) {
            return false;
        }
        if ($GLOBALS['perm']->have_studip_perm("dozent", $this['seminar_id'], $user_id)) {
            return true;
        }
        switch ($this['read_permission']) {
            case "all":
                return true;
            case "tutor":
                return $GLOBALS['perm']->have_studip_perm("tutor", $this['seminar_id'], $user_id);
            case "dozent":
                return $GLOBALS['perm']->have_studip_perm("dozent", $this['seminar_id'], $user_id);
            default:
                //statusgruppe_id
                $gruppe = Statusgruppen::find($this['read_permission']);
                if ($gruppe) {
                    return $gruppe->isMember($user_id);
                }
                if ($GLOBALS['perm']->have_studip_perm("autor", $this['seminar_id'], $user_id)) {
                    return true;
                }
        }
        return true;
    }

    public function isEditable($user_id = null)
    {
        $user_id || $user_id = $GLOBALS['user']->id;
        if (!$GLOBALS['perm']->have_studip_perm("autor", $this['seminar_id'], $user_id)) {
            return false;
        }
        if ($GLOBALS['perm']->have_studip_perm("dozent", $this['seminar_id'], $user_id)) {
            return true;
        }
        switch ($this['write_permission']) {
            case "all":
                return true;
            case "tutor":
                return $GLOBALS['perm']->have_studip_perm("tutor", $this['seminar_id'], $user_id);
            case "dozent":
                return $GLOBALS['perm']->have_studip_perm("dozent", $this['seminar_id'], $user_id);
            default:
                //statusgruppe_id
                $statusgruppe = Statusgruppen::find($this['read_permission']);
                if ($statusgruppe) {
                    return $statusgruppe->isMember($user_id);
                }
                if ($GLOBALS['perm']->have_studip_perm("autor", $this['seminar_id'], $user_id)) {
                    return true;
                }
        }
        return false;
    }

    public function wikiFormat()
    {
        if (filter_var(trim($this['content']), FILTER_VALIDATE_URL) !== false) {
            $allow = array(
                "allow-forms",
                "allow-modals",
                "allow-orientation-lock",
                "allow-pointer-lock",
                "allow-popups",
                //"allow-same-origin",
                "allow-scripts",
                "allow-presentation",
                "allow-top-navigation",
                "allow-top-navigation-by-user-activation"
            );
            return '<iframe sandbox="'.implode(" ", $allow).'"
                        src="'.htmlReady(trim($this['content'])).'"
                        style="width: 100%; height: 95vh; border: none;"></iframe>';
        }
        $markup = new SuperwikiFormat();
        $text = $markup->format(nl2br(trim($this['content'])));
        //$text = \Studip\Markup::apply(new SuperwikiFormat(), $this['content'], true);

        $pages = self::findBySQL("seminar_id = ? AND content IS NOT NULL AND content != '' ORDER BY CHAR_LENGTH(name) DESC", array($this['seminar_id']));
        foreach ($pages as $page) {
            if (($page->getId() !== $this->getId()) && $page->isReadable()) {
                $text = preg_replace(
                    "/(\b)".preg_quote($page['name'],"/")."/",
                    '$1<a href="'.URLHelper::getLink("plugins.php/superwiki/page/view/".$page->getId(), array('cid' => $page['seminar_id'])).'">'
                        .(version_compare($GLOBALS['SOFTWARE_VERSION'], "3.4", ">=")
                            ? Icon::create($page->settings['link_icon'], "clickable")->asImg(20, array('class' => "text-bottom"))
                            : Assets::image_path("icons/20/blue/".$page->settings['link_icon'], array('class' => "text-bottom")))
                        ." ".htmlReady($page['name']).'</a>',
                    $text
                );
            }
        }
        if (strpos($text, '<div class="superwiki_presentation') !== false) {
            $text = '<a class="superwiki_presentation starter"
                        href="#"
                        onClick="STUDIP.SuperWiki.requestFullscreen(); return false;"
                        title="'._("Diese Wikiseite ist eine Präsentation. Klicken Sie hier, um sie im Vollbildmodus darzustellen.").'"
                        style="background-image: url('."'".$GLOBALS['ABSOLUTE_URI_STUDIP']."plugins_packages/RasmusFuhse/SuperWiki/assets/presentation_white.svg'".')">'
                    .(version_compare($GLOBALS['SOFTWARE_VERSION'], "3.4", ">=")
                        ? Icon::create("play", "info_alt")->asImg(20, array('class' => "text-bottom"))
                        : Assets::image_path("icons/20/white/play"))
                    ._("Präsentation starten")
                    .'</a>'
                    .$text;
        }
        return $text ? sprintf(FORMATTED_CONTENT_WRAPPER, $text) : '';
    }

    public function getActiveUsers()
    {
        $statement = DBManager::get()->prepare("
            SELECT user_id, latest_change
            FROM superwiki_editors
            WHERE page_id = :page_id
                AND online >= UNIX_TIMESTAMP() - 6
        ");
        $statement->execute(array(
            'page_id' => $this->getId()
        ));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
