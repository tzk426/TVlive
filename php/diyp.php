<?php
header('Content-Type: application/json; charset=utf-8');

class EPGServer {
    private $cacheDir = __DIR__ . '/epg_cache/';
    private $cacheDuration = 3600; // 60分钟缓存
    
    // 多个EPG数据源配置
    private $epgSources = [
        'source1' => [
            'name' => '51zmt',
            'url' => 'http://epg.51zmt.top:8000/e1.xml.gz',
            'priority' => 1,
            'enabled' => true,
            'retry_on_not_found' => true // 找不到频道时是否尝试其他源
        ],
        'source2' => [
            'name' => 'gitee',
            'url' => 'https://gitee.com/gsls200808/xmltvepg/raw/master/e9.xml.gz',
            'priority' => 2,
            'enabled' => true,
            'retry_on_not_found' => true
        ],
        'source3' => [
            'name' => 'epg_pw',
            'url' => 'https://epg.pw/xmltv/epg_CN.xml.gz',
            'priority' => 3,
            'enabled' => true,
            'retry_on_not_found' => true
        ]
    ];
    
    // 记录尝试过的数据源
    private $triedSources = [];
    
    public function __construct() {
        // 创建缓存目录
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        // 按优先级排序
        uasort($this->epgSources, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
    }
    
    public function handleRequest() {
        $channel = isset($_GET['ch']) ? trim($_GET['ch']) : '';
        $date = isset($_GET['date']) ? $_GET['date'] : '';
        $source = isset($_GET['source']) ? $_GET['source'] : ''; // 可选：指定数据源
        $maxRetry = isset($_GET['max_retry']) ? intval($_GET['max_retry']) : 3; // 最大重试次数
        
        if (empty($channel) || empty($date)) {
            $this->sendResponse(400, '参数缺失: ch和date为必填参数');
            return;
        }
        
        // 验证日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->sendResponse(400, '日期格式错误，应为YYYY-MM-DD');
            return;
        }
        
        try {
            // 重置尝试记录
            $this->triedSources = [];
            
            // 获取EPG数据
            if ($source && isset($this->epgSources[$source])) {
                // 指定数据源
                $result = $this->searchChannelInSource($source, $channel, $date);
            } else {
                // 自动选择数据源，支持失败重试
                $result = $this->searchChannelInAllSources($channel, $date, $maxRetry);
            }
            
            if (!$result['found']) {
                // 汇总所有数据源的频道建议
                $allSuggestions = $this->getAllChannelSuggestions($channel);
                
                $this->sendResponse(404, '未找到指定频道和日期的节目单', [
                    'suggestions' => $allSuggestions,
                    'query_channel' => $channel,
                    'tried_sources' => $this->triedSources,
                    'message' => '已在 ' . count($this->triedSources) . ' 个数据源中查找，均未找到该频道'
                ]);
                return;
            }
            
            // 返回成功结果
            $response = [
                'code' => 200,
                'message' => '请求成功',
                'query_channel' => $channel,
                'matched_channel' => $result['channel_name'],
                'match_score' => $result['match_score'],
                'channel_id' => $result['channel_id'],
                'channel_name' => $result['channel_name'],
                'date' => $date,
                'epg_source' => $result['source_name'],
                'epg_source_url' => $result['source_url'],
                'tried_sources_count' => count($this->triedSources),
                'icon' => $result['icon'],
                'epg_data' => $result['programs']
            ];
            
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            $this->sendResponse(500, '服务器内部错误: ' . $e->getMessage());
        }
    }
    
    /**
     * 在指定数据源中搜索频道
     */
    private function searchChannelInSource($sourceId, $channel, $date) {
        $epgData = $this->getEPGDataFromSource($sourceId);
        
        // 记录尝试的数据源
        $this->triedSources[] = [
            'source_id' => $sourceId,
            'source_name' => $epgData ? $epgData['source_name'] : $this->epgSources[$sourceId]['name'],
            'source_url' => $epgData ? $epgData['source_url'] : $this->epgSources[$sourceId]['url'],
            'success' => $epgData ? true : false,
            'message' => $epgData ? '数据加载成功' : '数据加载失败'
        ];
        
        if (!$epgData) {
            return ['found' => false];
        }
        
        // 查找节目
        $result = $this->findProgramsWithFuzzy($epgData['data'], $channel, $date);
        
        if (empty($result['programs'])) {
            return [
                'found' => false,
                'source_name' => $epgData['source_name'],
                'source_url' => $epgData['source_url']
            ];
        }
        
        return [
            'found' => true,
            'source_name' => $epgData['source_name'],
            'source_url' => $epgData['source_url'],
            'channel_id' => $result['channel_id'],
            'channel_name' => $result['channel_name'],
            'match_score' => $result['match_score'],
            'icon' => $result['icon'],
            'programs' => $result['programs']
        ];
    }
    
    /**
     * 在所有启用的数据源中搜索频道
     */
    private function searchChannelInAllSources($channel, $date, $maxRetry = 3) {
        $retryCount = 0;
        
        foreach ($this->epgSources as $sourceId => $sourceConfig) {
            if (!$sourceConfig['enabled']) {
                continue;
            }
            
            // 检查是否需要重试
            if (!$sourceConfig['retry_on_not_found'] && $retryCount > 0) {
                continue;
            }
            
            $result = $this->searchChannelInSource($sourceId, $channel, $date);
            
            if ($result['found']) {
                return $result;
            }
            
            $retryCount++;
            if ($retryCount >= $maxRetry) {
                break;
            }
        }
        
        return ['found' => false];
    }
    
    /**
     * 获取所有数据源的频道建议
     */
    private function getAllChannelSuggestions($channel) {
        $allSuggestions = [];
        
        foreach ($this->epgSources as $sourceId => $sourceConfig) {
            if (!$sourceConfig['enabled']) {
                continue;
            }
            
            $epgData = $this->getEPGDataFromSource($sourceId);
            if (!$epgData) {
                continue;
            }
            
            $suggestions = $this->getChannelSuggestions($epgData['data'], $channel);
            if (!empty($suggestions)) {
                $allSuggestions = array_merge($allSuggestions, $suggestions);
            }
        }
        
        // 去重并排序
        $allSuggestions = array_unique($allSuggestions);
        usort($allSuggestions, function($a, $b) use ($channel) {
            $scoreA = $this->calculateSimilarity(
                $this->normalizeChannelName($a),
                $this->normalizeChannelName($channel)
            );
            $scoreB = $this->calculateSimilarity(
                $this->normalizeChannelName($b),
                $this->normalizeChannelName($channel)
            );
            return $scoreB <=> $scoreA;
        });
        
        // 只返回前15个建议
        return array_slice($allSuggestions, 0, 15);
    }
    
    /**
     * 从指定数据源获取EPG数据
     */
    private function getEPGDataFromSource($sourceId) {
        if (!isset($this->epgSources[$sourceId])) {
            return null;
        }
        
        $sourceConfig = $this->epgSources[$sourceId];
        $cacheFile = $this->cacheDir . 'epg_' . $sourceId . '.xml';
        
        // 检查缓存是否有效
        if (file_exists($cacheFile) && 
            time() - filemtime($cacheFile) < $this->cacheDuration) {
            $xmlContent = file_get_contents($cacheFile);
            $xmlData = simplexml_load_string($xmlContent);
            
            if ($xmlData) {
                return [
                    'data' => $xmlData,
                    'source_name' => $sourceConfig['name'],
                    'source_url' => $sourceConfig['url'],
                    'source_id' => $sourceId
                ];
            }
        }
        
        // 获取远程数据
        $compressedData = $this->fetchRemoteData($sourceConfig['url']);
        if (!$compressedData) {
            // 如果获取失败，尝试使用缓存（如果有）
            if (file_exists($cacheFile)) {
                $xmlContent = file_get_contents($cacheFile);
                $xmlData = simplexml_load_string($xmlContent);
                
                if ($xmlData) {
                    return [
                        'data' => $xmlData,
                        'source_name' => $sourceConfig['name'],
                        'source_url' => $sourceConfig['url'],
                        'source_id' => $sourceId
                    ];
                }
            }
            return null;
        }
        
        // 解压数据
        $xmlData = gzdecode($compressedData);
        if (!$xmlData) {
            // 如果不是gzip格式，直接使用原始数据
            $xmlData = $compressedData;
        }
        
        // 保存到缓存
        file_put_contents($cacheFile, $xmlData);
        
        $xml = simplexml_load_string($xmlData);
        if (!$xml) {
            return null;
        }
        
        return [
            'data' => $xml,
            'source_name' => $sourceConfig['name'],
            'source_url' => $sourceConfig['url'],
            'source_id' => $sourceId
        ];
    }
    
    /**
     * 获取所有可用数据源的状态
     */
    public function getSourcesStatus() {
        $status = [];
        
        foreach ($this->epgSources as $sourceId => $sourceConfig) {
            if (!$sourceConfig['enabled']) {
                continue;
            }
            
            $cacheFile = $this->cacheDir . 'epg_' . $sourceId . '.xml';
            $lastUpdated = file_exists($cacheFile) ? filemtime($cacheFile) : 0;
            $cacheValid = file_exists($cacheFile) && 
                         (time() - $lastUpdated) < $this->cacheDuration;
            
            $status[] = [
                'id' => $sourceId,
                'name' => $sourceConfig['name'],
                'url' => $sourceConfig['url'],
                'priority' => $sourceConfig['priority'],
                'enabled' => $sourceConfig['enabled'],
                'retry_on_not_found' => $sourceConfig['retry_on_not_found'],
                'cache_exists' => file_exists($cacheFile),
                'cache_valid' => $cacheValid,
                'last_updated' => $lastUpdated ? date('Y-m-d H:i:s', $lastUpdated) : '从未更新',
                'cache_file' => $cacheFile
            ];
        }
        
        return $status;
    }
    
    /**
     * 手动更新指定数据源的缓存
     */
    public function updateSourceCache($sourceId) {
        if (!isset($this->epgSources[$sourceId])) {
            return ['success' => false, 'message' => '数据源不存在'];
        }
        
        $sourceConfig = $this->epgSources[$sourceId];
        $cacheFile = $this->cacheDir . 'epg_' . $sourceId . '.xml';
        
        // 获取远程数据
        $compressedData = $this->fetchRemoteData($sourceConfig['url']);
        if (!$compressedData) {
            return ['success' => false, 'message' => '获取数据失败'];
        }
        
        // 解压数据
        $xmlData = gzdecode($compressedData);
        if (!$xmlData) {
            $xmlData = $compressedData;
        }
        
        // 保存到缓存
        $result = file_put_contents($cacheFile, $xmlData);
        
        if ($result) {
            return [
                'success' => true, 
                'message' => '缓存更新成功',
                'file_size' => filesize($cacheFile),
                'update_time' => date('Y-m-d H:i:s')
            ];
        } else {
            return ['success' => false, 'message' => '写入缓存文件失败'];
        }
    }
    
    private function fetchRemoteData($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 EPG Server/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_MAXREDIRS => 5
        ]);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode != 200 || !$data) {
            error_log("Failed to fetch EPG data from $url, HTTP Code: $httpCode, Error: $error");
            return false;
        }
        
        return $data;
    }
    
    /**
     * 模糊匹配查找节目
     */
    private function findProgramsWithFuzzy($epgData, $channelName, $date) {
        // 获取所有频道信息
        $allChannels = [];
        if (isset($epgData->channel)) {
            foreach ($epgData->channel as $channel) {
                $allChannels[] = [
                    'id' => (string)$channel['id'],
                    'name' => (string)$channel->{'display-name'},
                    'icon' => isset($channel->icon) ? (string)$channel->icon : '',
                    'original' => $channel
                ];
            }
        }
        
        // 首先尝试精确匹配
        $exactMatch = $this->exactMatchChannel($channelName, $allChannels);
        if ($exactMatch) {
            $programs = $this->getProgramsByChannelId($epgData, $exactMatch['id'], $date);
            return [
                'programs' => $programs,
                'channel_id' => $exactMatch['id'],
                'channel_name' => $exactMatch['name'],
                'icon' => $exactMatch['icon'],
                'match_score' => 100
            ];
        }
        
        // 如果精确匹配失败，使用模糊匹配
        $matchedChannel = $this->fuzzyMatchChannel($channelName, $allChannels);
        
        if (!$matchedChannel) {
            return [
                'programs' => [],
                'channel_id' => '',
                'channel_name' => '',
                'icon' => '',
                'match_score' => 0
            ];
        }
        
        // 查找该频道的节目
        $programs = $this->getProgramsByChannelId($epgData, $matchedChannel['id'], $date);
        
        return [
            'programs' => $programs,
            'channel_id' => $matchedChannel['id'],
            'channel_name' => $matchedChannel['name'],
            'icon' => $matchedChannel['icon'],
            'match_score' => $matchedChannel['match_score']
        ];
    }
    
    /**
     * 精确匹配频道
     */
    private function exactMatchChannel($query, $channels) {
        $query = trim($query);
        foreach ($channels as $channel) {
            if ($query === $channel['name']) {
                return $channel;
            }
        }
        return null;
    }
    
    /**
     * 模糊匹配频道
     */
    private function fuzzyMatchChannel($query, $channels) {
        $query = $this->normalizeChannelName($query);
        
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($channels as $channel) {
            $channelName = $this->normalizeChannelName($channel['name']);
            
            // 计算相似度
            $score = $this->calculateSimilarity($query, $channelName);
            
            // 提高精确数字匹配的分数
            if ($this->isExactNumberMatch($query, $channelName)) {
                $score = 100; // 数字完全匹配，给最高分
            }
            
            // 提高常见匹配的分数
            if ($this->isCommonMatch($query, $channelName)) {
                $score += 15;
            }
            
            // 完全匹配
            if ($query === $channelName) {
                $score = 100;
            }
            
            // 包含关系（双向）
            if (strpos($channelName, $query) !== false || strpos($query, $channelName) !== false) {
                $score = max($score, 85);
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $channel;
                $bestMatch['match_score'] = $score;
            }
        }
        
        // 设置阈值，低于65分认为不匹配
        if ($bestScore < 65) {
            return null;
        }
        
        return $bestMatch;
    }
    
    /**
     * 检查是否精确数字匹配
     */
    private function isExactNumberMatch($query, $channelName) {
        preg_match('/cctv(\d+)/', $query, $queryMatches);
        preg_match('/cctv(\d+)/', $channelName, $channelMatches);
        
        if (!empty($queryMatches[1]) && !empty($channelMatches[1])) {
            return $queryMatches[1] === $channelMatches[1];
        }
        
        return false;
    }
    
    /**
     * 标准化频道名称
     */
    private function normalizeChannelName($name) {
        $name = trim($name);
        
        // 转换为小写
        $name = mb_strtolower($name, 'UTF-8');
        
        // 移除所有空白字符
        $name = preg_replace('/\s+/', '', $name);
        
        // 1. 首先处理CCTV数字频道（保留完整数字）
        if (preg_match('/cctv[-\s_]*(\d+)/', $name, $matches)) {
            $name = 'cctv' . $matches[1];
        }
        
        // 2. 处理中文CCTV变体
        $cctvPatterns = [
            '/央视[-\s_]*(\d+)/' => 'cctv$1',
            '/中央[-\s_]*(\d+)/' => 'cctv$1',
            '/cc[-\s_]*tv[-\s_]*(\d+)/' => 'cctv$1',
        ];
        
        foreach ($cctvPatterns as $pattern => $replacement) {
            if (preg_match($pattern, $name, $matches)) {
                $name = preg_replace($pattern, $replacement, $name);
                break;
            }
        }
        
        // 3. 处理卫视系列
        $name = preg_replace('/电视台/', '卫视', $name);
        $name = preg_replace('/台$/', '', $name);
        
        // 4. 移除高清标识
        $name = preg_replace('/高清$/', '', $name);
        $name = preg_replace('/hd$/', '', $name);
        $name = preg_replace('/\(hd\)/', '', $name);
        
        // 5. 移除常见后缀
        $suffixes = ['综合', '娱乐', '新闻', '体育', '电影', '戏曲', '少儿', '农业', '军事'];
        foreach ($suffixes as $suffix) {
            $name = preg_replace('/' . $suffix . '$/', '', $name);
        }
        
        // 6. 移除其他特殊字符
        $name = preg_replace('/[\(\)（）【】\[\]\.\-_\+\=\|]/u', '', $name);
        
        return $name;
    }
    
    /**
     * 计算相似度
     */
    private function calculateSimilarity($str1, $str2) {
        if ($str1 === $str2) {
            return 100;
        }
        
        // 如果是CCTV数字频道，特殊处理
        if (preg_match('/^cctv(\d+)$/', $str1, $matches1) && 
            preg_match('/^cctv(\d+)$/', $str2, $matches2)) {
            if ($matches1[1] === $matches2[1]) {
                return 100;
            }
            return 30;
        }
        
        // 使用similar_text
        similar_text($str1, $str2, $percent);
        
        // 使用编辑距离
        $levenshtein = levenshtein($str1, $str2);
        $maxLen = max(mb_strlen($str1, 'UTF-8'), mb_strlen($str2, 'UTF-8'));
        $levPercent = $maxLen > 0 ? (1 - $levenshtein / $maxLen) * 100 : 0;
        
        return max($percent, $levPercent);
    }
    
    /**
     * 检查是否是常见匹配
     */
    private function isCommonMatch($query, $channelName) {
        $commonMatches = [
            'cctv1' => ['央视一套', '中央一台'],
            'cctv2' => ['央视二套', '中央二台'],
            'cctv5' => ['央视五套', '中央五台', '体育频道'],
            'cctv6' => ['央视六套', '中央六台', '电影频道'],
            'cctv8' => ['央视八套', '中央八台', '电视剧频道'],
            'cctv13' => ['央视十三套', '中央十三台', '新闻频道'],
            '湖南卫视' => ['湖南', '湖南台'],
            '浙江卫视' => ['浙江', '浙江台'],
            '江苏卫视' => ['江苏', '江苏台'],
            '东方卫视' => ['东方', '东方台', '上海卫视'],
            '北京卫视' => ['北京', '北京台'],
            '广东卫视' => ['广东', '广东台'],
            '深圳卫视' => ['深圳', '深圳台'],
            '凤凰卫视' => ['凤凰', '凤凰台'],
            '星空卫视' => ['星空', '星空台'],
        ];
        
        foreach ($commonMatches as $key => $variants) {
            if (strpos($channelName, $this->normalizeChannelName($key)) !== false) {
                $normalizedKey = $this->normalizeChannelName($key);
                foreach ($variants as $variant) {
                    $normalizedVariant = $this->normalizeChannelName($variant);
                    if (strpos($query, $normalizedVariant) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * 根据频道ID获取节目
     */
    private function getProgramsByChannelId($epgData, $channelId, $date) {
        $programs = [];
        
        if (isset($epgData->programme)) {
            foreach ($epgData->programme as $programme) {
                $programChannelId = (string)$programme['channel'];
                if ($programChannelId == $channelId) {
                    $start = (string)$programme['start'];
                    $stop = (string)$programme['stop'];
                    
                    // 解析时间
                    $startTime = DateTime::createFromFormat('YmdHis O', $start);
                    $endTime = DateTime::createFromFormat('YmdHis O', $stop);
                    
                    if ($startTime && $startTime->format('Y-m-d') == $date) {
                        $program = [
                            'start' => $startTime->format('H:i'),
                            'end' => $endTime ? $endTime->format('H:i') : '00:00',
                            'title' => (string)$programme->title,
                            'desc' => isset($programme->desc) ? (string)$programme->desc : ''
                        ];
                        $programs[] = $program;
                    }
                }
            }
        }
        
        return $programs;
    }
    
    /**
     * 获取频道建议
     */
    private function getChannelSuggestions($epgData, $query) {
        $suggestions = [];
        $query = $this->normalizeChannelName($query);
        
        $isCCTVNumberQuery = preg_match('/cctv(\d+)/', $query, $queryMatches);
        $queryNumber = $isCCTVNumberQuery ? $queryMatches[1] : null;
        
        if (isset($epgData->channel)) {
            foreach ($epgData->channel as $channel) {
                $channelName = (string)$channel->{'display-name'};
                $normalizedChannel = $this->normalizeChannelName($channelName);
                
                if ($isCCTVNumberQuery && preg_match('/cctv(\d+)/', $normalizedChannel, $channelMatches)) {
                    if ($queryNumber === $channelMatches[1]) {
                        $score = 100;
                    } else {
                        $score = 30;
                    }
                } else {
                    $score = $this->calculateSimilarity($query, $normalizedChannel);
                }
                
                if ($score > 50) {
                    $suggestions[] = [
                        'name' => $channelName,
                        'score' => $score,
                        'id' => (string)$channel['id']
                    ];
                }
            }
        }
        
        // 按分数排序
        usort($suggestions, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // 只返回前10个建议
        $suggestions = array_slice($suggestions, 0, 10);
        
        return array_map(function($s) {
            return $s['name'];
        }, $suggestions);
    }
    
    private function sendResponse($code, $message, $data = []) {
        http_response_code($code >= 400 ? $code : 200);
        
        $response = [
            'code' => $code,
            'message' => $message
        ];
        
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

// 处理跨域请求
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 路由处理
$action = isset($_GET['action']) ? $_GET['action'] : 'epg';
$epgServer = new EPGServer();

switch ($action) {
    case 'epg':
        // 默认EPG查询
        $epgServer->handleRequest();
        break;
        
    case 'sources':
        // 查看数据源状态
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 200,
            'message' => '数据源状态',
            'sources' => $epgServer->getSourcesStatus()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
        
    case 'update':
        // 手动更新缓存
        $sourceId = isset($_GET['source']) ? $_GET['source'] : '';
        if (empty($sourceId)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'code' => 400,
                'message' => '请指定要更新的数据源ID'
            ]);
            break;
        }
        
        $result = $epgServer->updateSourceCache($sourceId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => $result['success'] ? 200 : 500,
            'message' => $result['message'],
            'data' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
        
    case 'help':
        // 帮助信息
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 200,
            'message' => 'EPG服务API说明',
            'endpoints' => [
                [
                    'path' => '/epg.php',
                    'method' => 'GET',
                    'description' => '查询EPG节目单',
                    'parameters' => [
                        'ch' => '频道名称（支持模糊匹配）',
                        'date' => '日期（格式：YYYY-MM-DD）',
                        'source' => '可选，指定数据源ID',
                        'max_retry' => '可选，最大重试次数，默认3',
                        'action' => '可选，其他功能（sources/update/help）'
                    ],
                    'example' => [
                        '查询CCTV1节目单' => '/epg.php?ch=CCTV1&date=2024-01-15',
                        '指定数据源' => '/epg.php?ch=CCTV1&date=2024-01-15&source=source1',
                        '多源查找翡翠台' => '/epg.php?ch=翡翠&date=2024-01-15&max_retry=3',
                        '查看数据源' => '/epg.php?action=sources',
                        '更新缓存' => '/epg.php?action=update&source=source1'
                    ]
                ]
            ],
            'available_sources' => [
                'source1' => '51zmt - 老张-国内EPG源',
                'source2' => 'gitee - GitHub镜像源',
                'source3' => 'epg_pw - epg_pw源'
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        $epgServer->handleRequest();
        break;
}