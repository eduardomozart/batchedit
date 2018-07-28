<?php

/**
 * Plugin BatchEdit: Search and replace engine
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Mykola Ostrovskyy <dwpforge@gmail.com>
 */

require_once(DOKU_PLUGIN . 'batchedit/interface.php');

class BatcheditException extends Exception {

    private $arguments;

    /**
     * Accepts message id followed by optional arguments.
     */
    public function __construct($messageId) {
        parent::__construct($messageId);

        $this->arguments = func_get_args();
    }

    /**
     *
     */
    public function getArguments() {
        return $this->arguments;
    }
}

class BatcheditEmptyNamespaceException extends BatcheditException {

    /**
     *
     */
    public function __construct($namespace) {
        parent::__construct('err_emptyns', $namespace);
    }
}

class BatcheditPageApplyException extends BatcheditException {

    /**
     * Accepts message and page ids followed by optional arguments.
     */
    public function __construct($messageId, $pageId) {
        call_user_func_array('parent::__construct', func_get_args());
    }
}

class BatcheditAccessControlException extends BatcheditPageApplyException {

    /**
     *
     */
    public function __construct($pageId) {
        parent::__construct('war_norights', $pageId);
    }
}

class BatcheditPageLockedException extends BatcheditPageApplyException {

    /**
     *
     */
    public function __construct($pageId, $lockedBy) {
        parent::__construct('war_pagelock', $pageId, $lockedBy);
    }
}

class BatcheditMatch implements Serializable {

    const CONTEXT_LENGTH = 50;
    const CONTEXT_MAX_LINES = 3;

    private $pageOffset;
    private $originalText;
    private $replacedText;
    private $contextBefore;
    private $contextAfter;
    private $marked;
    private $applied;

    /**
     *
     */
    public function __construct($pageText, $pageOffset, $text, $regexp, $replacement) {
        $this->pageOffset = $pageOffset;
        $this->originalText = $text;
        $this->replacedText = preg_replace($regexp, $replacement, $text);
        $this->contextBefore = $this->cropContextBefore($pageText, $pageOffset);
        $this->contextAfter = $this->cropContextAfter($pageText, $pageOffset + strlen($text));
        $this->marked = FALSE;
        $this->applied = FALSE;
    }

    /**
     *
     */
    public function getPageOffset() {
        return $this->pageOffset;
    }

    /**
     *
     */
    public function getOriginalText() {
        return $this->originalText;
    }

    /**
     *
     */
    public function getReplacedText() {
        return $this->replacedText;
    }

    /**
     *
     */
    public function getContextBefore() {
        return $this->contextBefore;
    }

    /**
     *
     */
    public function getContextAfter() {
        return $this->contextAfter;
    }

    /**
     *
     */
    public function mark($marked = TRUE) {
        $this->marked = $marked;
    }

    /**
     *
     */
    public function isMarked() {
        return $this->marked;
    }

    /**
     *
     */
    public function apply($pageText, $offsetDelta) {
        $pageOffset = $this->pageOffset + $offsetDelta;
        $before = substr($pageText, 0, $pageOffset);
        $after = substr($pageText, $pageOffset + strlen($this->originalText));

        $this->applied = TRUE;

        return $before . $this->replacedText . $after;
    }

    /**
     *
     */
    public function rollback() {
        $this->applied = FALSE;
    }

    /**
     *
     */
    public function isApplied() {
        return $this->applied;
    }

    /**
     *
     */
    public function serialize() {
        return serialize(array($this->pageOffset, $this->originalText, $this->replacedText,
                $this->contextBefore, $this->contextAfter, $this->marked, $this->applied));
    }

    /**
     *
     */
    public function unserialize($data) {
        list($this->pageOffset, $this->originalText, $this->replacedText,
                $this->contextBefore, $this->contextAfter, $this->marked, $this->applied) = unserialize($data);
    }

    /**
     *
     */
    private function cropContextBefore($pageText, $pageOffset) {
        $length = self::CONTEXT_LENGTH;
        $offset = $pageOffset - $length;

        if ($offset < 0) {
            $length += $offset;
            $offset = 0;
        }

        $context = substr($pageText, $offset, $length);
        $count = preg_match_all('/\n/', $context, $match, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($count > self::CONTEXT_MAX_LINES) {
            $context = substr($context, $match[$count - self::CONTEXT_MAX_LINES - 1][0][1] + 1);
        }

        return $context;
    }

    /**
     *
     */
    private function cropContextAfter($pageText, $pageOffset) {
        $context = substr($pageText, $pageOffset, self::CONTEXT_LENGTH);
        $count = preg_match_all('/\n/', $context, $match, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($count > self::CONTEXT_MAX_LINES) {
            $context = substr($context, 0, $match[self::CONTEXT_MAX_LINES][0][1]);
        }

        return $context;
    }
}

class BatcheditPage implements Serializable {

    private $id;
    private $matches;

    /**
     *
     */
    public function __construct($id) {
        $this->id = $id;
        $this->matches = array();
    }

    /**
     *
     */
    public function getId() {
        return $this->id;
    }

    /**
     *
     */
    public function findMatches($regexp, $replacement, $limit) {
        $text = rawWiki($this->id);
        $count = @preg_match_all($regexp, $text, $match, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($count === FALSE) {
            throw new BatcheditException('err_pregfailed');
        }

        $interrupted = FALSE;

        if ($limit >= 0 && $count > $limit) {
            $count = $limit;
            $interrupted = TRUE;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->addMatch($text, $match[$i][0][1], $match[$i][0][0], $regexp, $replacement);
        }

        return $interrupted;
    }

    /**
     *
     */
    public function getMatches() {
        return $this->matches;
    }

    /**
     *
     */
    public function markMatch($offset) {
        if (array_key_exists($offset, $this->matches)) {
            $this->matches[$offset]->mark();
        }
    }

    /**
     *
     */
    public function hasMarkedMatches() {
        $result = FALSE;

        foreach ($this->matches as $match) {
            if ($match->isMarked()) {
                $result = TRUE;
                break;
            }
        }

        return $result;
    }

    /**
     *
     */
    public function hasUnmarkedMatches() {
        $result = FALSE;

        foreach ($this->matches as $match) {
            if (!$match->isMarked()) {
                $result = TRUE;
                break;
            }
        }

        return $result;
    }

    /**
     *
     */
    public function hasUnappliedMatches() {
        $result = FALSE;

        foreach ($this->matches as $match) {
            if (!$match->isApplied()) {
                $result = TRUE;
                break;
            }
        }

        return $result;
    }

    /**
     *
     */
    public function applyMatches($summary, $minorEdit) {
        try {
            $this->lock();

            $text = rawWiki($this->id);
            $originalLength = strlen($text);
            $count = 0;

            foreach ($this->matches as $match) {
                if ($match->isMarked()) {
                    $text = $match->apply($text, strlen($text) - $originalLength);
                    $count++;
                }
            }

            saveWikiText($this->id, $text, $summary, $minorEdit);
            unlock($this->id);
        }
        catch (Exception $error) {
            $this->rollbackMatches();

            throw $error;
        }

        return $count;
    }

    /**
     *
     */
    public function serialize() {
        return serialize(array($this->id, $this->matches));
    }

    /**
     *
     */
    public function unserialize($data) {
        list($this->id, $this->matches) = unserialize($data);
    }

    /**
     *
     */
    private function addMatch($text, $offset, $matched, $regexp, $replacement) {
        $this->matches[$offset] = new BatcheditMatch($text, $offset, $matched, $regexp, $replacement);
    }

    /**
     *
     */
    private function lock() {
        if (auth_quickaclcheck($this->id) < AUTH_EDIT) {
            throw new BatcheditAccessControlException($this->id);
        }

        $lockedBy = checklock($this->id);

        if ($lockedBy != FALSE) {
            throw new BatcheditPageLockedException($this->id, $lockedBy);
        }

        lock($this->id);
    }

    /**
     *
     */
    private function rollbackMatches() {
        foreach ($this->matches as $match) {
            $match->rollback();
        }
    }
}

class BatcheditSessionCache {

    const EXPIRATION_TIME = 3600;

    private $directory;

    /**
     *
     */
    public function __construct() {
        global $conf;

        $this->directory = $conf['cachedir'] . '/batchedit';

        io_mkdir_p($this->directory);
    }

    /**
     *
     */
    public function __destruct() {
        $this->prune();
    }

    /**
     *
     */
    public function save($id, $name, $data) {
        file_put_contents($this->getFileName($id, $name), serialize($data));
    }

    /**
     *
     */
    public function load($id, $name) {
        return @unserialize(file_get_contents($this->getFileName($id, $name)));
    }

    /**
     *
     */
    public function isValid($id) {
        global $conf;

        $propsTime = @filemtime($this->getFileName($id, 'props'));
        $matchesTime = @filemtime($this->getFileName($id, 'matches'));

        if ($propsTime === FALSE || $matchesTime === FALSE) {
            return FALSE;
        }

        $now = time();

        if ($propsTime + self::EXPIRATION_TIME < $now || $matchesTime + self::EXPIRATION_TIME < $now) {
            return FALSE;
        }

        $changeLogTime = @filemtime($conf['changelog']);

        if ($changeLogTime !== FALSE && ($changeLogTime > $propsTime || $changeLogTime > $matchesTime)) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     *
     */
    public function expire($id) {
        @unlink($this->getFileName($id, 'props'));
        @unlink($this->getFileName($id, 'matches'));
    }

    /**
     *
     */
    private function getFileName($id, $ext) {
        return $this->directory . '/' . $id . '.' . $ext;
    }

    /**
     *
     */
    private function prune() {
        $marker = $this->directory . '/_prune';
        $lastPrune = @filemtime($marker);
        $now = time();

        if ($lastPrune !== FALSE && $lastPrune + self::EXPIRATION_TIME > $now) {
            return;
        }

        $directory = new GlobIterator($this->directory . '/*.*');
        $expired = array();

        foreach ($directory as $fileInfo) {
            if ($fileInfo->getMTime() + self::EXPIRATION_TIME < $now) {
                $expired[pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME)] = TRUE;
            }
        }

        foreach ($expired as $id => $dummy) {
            $this->expire($id);
        }

        @touch($marker);
    }
}

class BatcheditSession {

    private $id;
    private $error;
    private $warnings;
    private $pages;
    private $matches;
    private $edits;
    private $cache;

    /**
     *
     */
    public function __construct() {
        $this->id = $this->generateId();
        $this->error = NULL;
        $this->warnings = array();
        $this->pages = array();
        $this->matches = 0;
        $this->edits = 0;
        $this->cache = new BatcheditSessionCache();
    }

    /**
     *
     */
    public function setId($id) {
        $this->id = $id;
    }

    /**
     *
     */
    public function getId() {
        return $this->id;
    }

    /**
     *
     */
    public function load($request, $config) {
        $this->id = $request->getSessionId();

        if (!$this->cache->isValid($this->id)) {
            return FALSE;
        }

        $properties = $this->loadArray('props');

        if (!is_array($properties) || !empty(array_diff_assoc($properties, $this->getProperties($request, $config)))) {
            return FALSE;
        }

        $matches = $this->loadArray('matches');

        if (!is_array($matches)) {
            return FALSE;
        }

        list($warnings, $this->matches, $this->pages) = $matches;

        $this->warnings = array_filter($warnings, function ($message) {
            return $message->getId() != 'war_timeout';
        });

        return TRUE;
    }

    /**
     *
     */
    public function save($request, $config) {
        $this->saveArray('props', $this->getProperties($request, $config));
        $this->saveArray('matches', array($this->warnings, $this->matches, $this->pages));
    }

    /**
     *
     */
    public function expire() {
        $this->cache->expire($this->id);
    }

    /**
     *
     */
    public function setError($error) {
        $this->error = new BatcheditErrorMessage($error->getArguments());
        $this->pages = array();
        $this->matches = 0;
        $this->edits = 0;
    }

    /**
     * Accepts BatcheditException instance or message id followed by optional arguments.
     */
    public function addWarning($warning) {
        if ($warning instanceof BatcheditException) {
            $this->warnings[] = new BatcheditWarningMessage($warning->getArguments());
        }
        else {
            $this->warnings[] = new BatcheditWarningMessage(func_get_args());
        }
    }

    /**
     *
     */
    public function getMessages() {
        if ($this->error != NULL) {
            return array($this->error);
        }

        return $this->warnings;
    }

    /**
     *
     */
    public function addPage($page) {
        $this->pages[$page->getId()] = $page;
        $this->matches += count($page->getMatches());
    }

    /**
     *
     */
    public function getPages() {
        return $this->pages;
    }

    /**
     *
     */
    public function getPageCount() {
        return count($this->pages);
    }

    /**
     *
     */
    public function getMatchCount() {
        return $this->matches;
    }

    /**
     *
     */
    public function addEdits($edits) {
        $this->edits += $edits;
    }

    /**
     *
     */
    public function getEditCount() {
        return $this->edits;
    }

    /**
     *
     */
    private function generateId() {
        global $USERINFO;

        $time = gettimeofday();

        return md5($time['sec'] . $time['usec'] . $USERINFO['name'] . $USERINFO['mail']);
    }

    /**
     *
     */
    private function getProperties($request, $config) {
        global $USERINFO;

        $properties = array();

        $properties['username'] = $USERINFO['name'];
        $properties['usermail'] = $USERINFO['mail'];
        $properties['namespace'] = $request->getNamespace();
        $properties['regexp'] = $request->getRegexp();
        $properties['replacement'] = $request->getReplacement();
        $properties['searchlimit'] = $config->getConf('searchlimit') ? $config->getConf('searchmax') : 0;

        return $properties;
    }

    /**
     *
     */
    private function saveArray($name, $array) {
        $this->cache->save($this->id, $name, $array);
    }

    /**
     *
     */
    private function loadArray($name) {
        return $this->cache->load($this->id, $name);
    }
}

class BatcheditEngine {

    // This constant is used to take into account the time that plugin spends outside
    // of the engine. For example, this can be time spent by DokuWiki itself, time for
    // request parsing, session loading and saving, etc.
    const NON_ENGINE_TIME = 5;

    private $session;
    private $startTime;

    /**
     *
     */
    public function __construct($session) {
        $this->session = $session;
        $this->startTime = time();
    }

    /**
     *
     */
    public function findMatches($namespace, $regexp, $replacement, $limit) {
        foreach ($this->getPageIndex($namespace) as $pageId) {
            $page = new BatcheditPage(trim($pageId));
            $interrupted = $page->findMatches($regexp, $replacement, $limit - $this->session->getMatchCount());

            if (count($page->getMatches()) > 0) {
                $this->session->addPage($page);
            }

            if ($interrupted) {
                $this->session->addWarning('war_searchlimit');
                break;
            }

            if ($this->isOperationTimedOut()) {
                $this->session->addWarning('war_timeout');
                break;
            }
        }

        if ($this->session->getMatchCount() == 0) {
            $this->session->addWarning('war_nomatches');
        }
    }

    /**
     *
     */
    public function markRequestedMatches($request) {
        $pages = $this->session->getPages();

        foreach ($request as $matchId) {
            list($pageId, $offset) = explode('#', $matchId);

            if (array_key_exists($pageId, $pages)) {
                $pages[$pageId]->markMatch($offset);
            }
        }
    }

    /**
     *
     */
    public function applyMatches($summary, $minorEdit) {
        foreach ($this->session->getPages() as $page) {
            if ($page->hasMarkedMatches() && $page->hasUnappliedMatches()) {
                try {
                    $this->session->addEdits($page->applyMatches($summary, $minorEdit));
                }
                catch (BatcheditPageApplyException $error) {
                    $this->session->addWarning($error);
                }

                if ($this->isOperationTimedOut()) {
                    $this->session->addWarning('war_timeout');
                    break;
                }
            }
        }
    }

    /**
     *
     */
    private function getPageIndex($namespace) {
        global $conf;

        if (!@file_exists($conf['indexdir'] . '/page.idx')) {
            throw new BatcheditException('err_idxaccess');
        }

        require_once(DOKU_INC . 'inc/indexer.php');

        $index = idx_getIndex('page', '');

        if (count($index) == 0) {
            throw new BatcheditException('err_emptyidx');
        }

        if ($namespace != '') {
            if ($namespace == ':') {
                $pattern = "\033^[^:]+$\033";
            }
            else {
                $pattern = "\033^" . $namespace . "\033";
            }

            $index = array_filter($index, function ($pageId) use ($pattern) {
                return preg_match($pattern, $pageId) == 1;
            });

            if (count($index) == 0) {
                throw new BatcheditEmptyNamespaceException($namespace);
            }
        }

        return $index;
    }

    /**
     *
     */
    private function isOperationTimedOut() {
        // On different systems max_execution_time can be used in diffenent ways: it can track
        // either real time or only user time excluding all system calls. Here we enforce real
        // time limit, which could be more strict then what PHP would do, but is easier to
        // implement in a cross-platform way and easier for a user to understand.
        return time() - $this->startTime >= ini_get('max_execution_time') - self::NON_ENGINE_TIME;
    }
}
