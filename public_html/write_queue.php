<?php
/**
 * Per-User Write Queue — حل جذري لـ concurrent editing
 * 
 * كل user كيكتب فـ queue خاص → الـ API كيدمج الـ queues بالتسلسل
 */

class WriteQueue {
    private $queueDir;
    private $lockFile;
    
    public function __construct() {
        $this->queueDir = sys_get_temp_dir() . '/crm_write_queue';
        $this->lockFile = $this->queueDir . '/merge.lock';
        
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0755, true);
        }
    }
    
    /**
     * إضافة write request للـ queue
     */
    public function enqueue($userId, $key, $data, $timestamp) {
        $queueFile = $this->queueDir . '/' . $userId . '_' . time() . '_' . uniqid() . '.json';
        $queueData = [
            'user_id' => $userId,
            'key' => $key,
            'data' => $data,
            'timestamp' => $timestamp,
            'created_at' => time()
        ];
        
        file_put_contents($queueFile, json_encode($queueData));
        return $queueFile;
    }
    
    /**
     * معالجة كل الـ queues (merge)
     */
    public function processAll($mergeCallback) {
        // Acquire merge lock
        $lockHandle = fopen($this->lockFile, 'c');
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            // Another merge is running
            fclose($lockHandle);
            return false;
        }
        
        try {
            // Get all queue files
            $files = glob($this->queueDir . '/*.json');
            if (empty($files)) {
                return true;
            }
            
            // Sort by timestamp (oldest first)
            usort($files, function($a, $b) {
                $dataA = json_decode(file_get_contents($a), true);
                $dataB = json_decode(file_get_contents($b), true);
                return $dataA['timestamp'] - $dataB['timestamp'];
            });
            
            // Process each queue
            foreach ($files as $file) {
                $queueData = json_decode(file_get_contents($file), true);
                if (!$queueData) continue;
                
                // Call merge callback
                $mergeCallback($queueData['key'], $queueData['data'], $queueData['user_id']);
                
                // Remove processed queue
                unlink($file);
            }
            
            return true;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
    
    /**
     * تنظيف الـ queues القديمة (أكثر من 1 ساعة)
     */
    public function cleanup() {
        $files = glob($this->queueDir . '/*.json');
        $oneHourAgo = time() - 3600;
        
        foreach ($files as $file) {
            if (filemtime($file) < $oneHourAgo) {
                unlink($file);
            }
        }
    }
}
