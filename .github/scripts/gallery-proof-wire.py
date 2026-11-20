from pathlib import Path
import hashlib

def update(path, edit):
    p = Path(path)
    source = p.read_text()
    revised = edit(source)
    assert revised != source
    print(path, hashlib.sha256(source.encode()).hexdigest(), hashlib.sha256(revised.encode()).hexdigest())
    p.write_text(revised)

def guard_pdo(source):
    anchor = '    ): PDO {\n'
    assert source.count(anchor) == 1
    source = source.replace(anchor, anchor + '        \\localeProofBeforeOpen($dsn);\n')
    for original in ('new PDO($dsn, $username, $password, $options)', 'PDO::connect($dsn, $username, $password, $options)'):
        assert source.count('return ' + original + ';') == 1
        source = source.replace('return ' + original + ';', 'return \\localeProofAfterOpen(' + original + ');')
    return source

def time_adapter(source):
    begin = source.index('    public function query(')
    end = source.index('    protected function _prepareQuery(', begin)
    query = source[begin:end]
    def insert(text, anchor, update_text):
        assert text.count(anchor) == 1
        return text.replace(anchor, update_text)
    query = insert(query, '        $this->_debugTimer();', '        $galleryStart = hrtime(true);\n        $galleryIssue = null;\n        $this->_debugTimer();')
    query = insert(query, '            $this->_connect();', '            $this->_connect();\n            \\localeProofBeforeQuery($this, $this->_connection);')
    query = insert(query, '        } catch (\\Exception $e) {', '''        } catch (\\Exception $e) {
            $galleryIssue = ['class' => $e::class, 'code' => $e->getCode(), 'sqlite_locked' => str_contains($e->getMessage(), 'database is locked')];''')
    query = insert(query, '            $span?->end();', """            $span?->end();
            \\galleryProofSlowQuery((string) $sql, $galleryStart, $galleryIssue);""")
    source = source[:begin] + query + source[end:]
    start = source.index('    public function getLock(')
    end = source.index('    public function releaseLock(', start)
    lock = source[start:end]
    lock = insert(lock, '        $this->_connect();', '        $galleryStart = hrtime(true);\n        $this->_connect();')
    lock = insert(lock, '                return true;', "                \\galleryProofSlow('named_lock_acquired', $galleryStart);\n                return true;")
    lock = insert(lock, '        return false;\n    }', "        \\galleryProofSlow('named_lock_timeout', $galleryStart);\n        return false;\n    }")
    return source[:start] + lock + source[end:]

update('vendor/doctrine/dbal/src/Driver/PDO/PDOConnect.php', guard_pdo)
update('lib/Maho/Db/Adapter/Pdo/Sqlite.php', time_adapter)
