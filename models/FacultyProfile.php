<?php
require_once __DIR__ . '/../config/database.php';

class FacultyProfile {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // --- User Slug and Photo ---
    
    public function getUserIdBySlug($slug) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE profile_slug = ?");
        $stmt->execute([$slug]);
        return $stmt->fetchColumn();
    }
    
    public function updateProfileSlug($userId, $slug) {
        // Ensure uniqueness
        $baseSlug = $slug;
        $counter = 1;
        while ($this->slugExists($slug, $userId)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        $stmt = $this->db->prepare("UPDATE users SET profile_slug = ? WHERE id = ?");
        return $stmt->execute([$slug, $userId]);
    }
    
    private function slugExists($slug, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) FROM users WHERE profile_slug = ?";
        $params = [$slug];
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }
    
    // --- Bio & Links ---
    
    public function getProfile($userId) {
        $stmt = $this->db->prepare("SELECT * FROM faculty_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        if (!$profile) {
            // Return empty structure
            return [
                'bio' => '', 'publon_link' => '', 'scopus_link' => '',
                'google_scholar_link' => '', 'other_link' => '',
                'whatsapp_link' => '', 'facebook_link' => '', 'youtube_link' => ''
            ];
        }
        return $profile;
    }
    
    public function saveProfile($userId, $data) {
        $stmt = $this->db->prepare("SELECT id FROM faculty_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            $stmt = $this->db->prepare("UPDATE faculty_profiles SET bio=?, publon_link=?, scopus_link=?, google_scholar_link=?, other_link=?, whatsapp_link=?, facebook_link=?, youtube_link=?, updated_at=CURRENT_TIMESTAMP WHERE user_id=?");
            return $stmt->execute([
                $data['bio'], $data['publon_link'], $data['scopus_link'], 
                $data['google_scholar_link'], $data['other_link'], 
                $data['whatsapp_link'], $data['facebook_link'], $data['youtube_link'],
                $userId
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO faculty_profiles (user_id, bio, publon_link, scopus_link, google_scholar_link, other_link, whatsapp_link, facebook_link, youtube_link) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            return $stmt->execute([
                $userId, $data['bio'], $data['publon_link'], $data['scopus_link'], 
                $data['google_scholar_link'], $data['other_link'], 
                $data['whatsapp_link'], $data['facebook_link'], $data['youtube_link']
            ]);
        }
    }
    
    // --- Education ---
    
    public function getEducation($userId) {
        $stmt = $this->db->prepare("SELECT * FROM faculty_education WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function addEducation($userId, $data) {
        $stmt = $this->db->prepare("INSERT INTO faculty_education (user_id, degree_name, institution, passing_year) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $data['degree_name'], $data['institution'], $data['passing_year']]);
    }
    
    public function deleteEducation($userId, $id) {
        $stmt = $this->db->prepare("DELETE FROM faculty_education WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
    
    // --- Awards ---
    
    public function getAwards($userId) {
        $stmt = $this->db->prepare("SELECT * FROM faculty_awards WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function addAward($userId, $data) {
        $stmt = $this->db->prepare("INSERT INTO faculty_awards (user_id, title, honored_by) VALUES (?, ?, ?)");
        return $stmt->execute([$userId, $data['title'], $data['honored_by']]);
    }
    
    public function deleteAward($userId, $id) {
        $stmt = $this->db->prepare("DELETE FROM faculty_awards WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
    
    // --- Publications ---
    
    public function getPublications($userId) {
        $stmt = $this->db->prepare("SELECT * FROM faculty_publications WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function addPublication($userId, $data) {
        $stmt = $this->db->prepare("INSERT INTO faculty_publications (user_id, name, published_in) VALUES (?, ?, ?)");
        return $stmt->execute([$userId, $data['name'], $data['published_in']]);
    }
    
    public function deletePublication($userId, $id) {
        $stmt = $this->db->prepare("DELETE FROM faculty_publications WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
    
    // --- FDPs ---
    
    public function getFDPs($userId) {
        $stmt = $this->db->prepare("SELECT * FROM faculty_fdps WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function addFDP($userId, $data) {
        $stmt = $this->db->prepare("INSERT INTO faculty_fdps (user_id, title, week_duration, approved_by, venue) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $data['title'], $data['week_duration'], $data['approved_by'], $data['venue']]);
    }
    
    public function deleteFDP($userId, $id) {
        $stmt = $this->db->prepare("DELETE FROM faculty_fdps WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
    
    // --- Gallery ---
    
    public function getGallery($userId) {
        $stmt = $this->db->prepare("SELECT * FROM faculty_gallery WHERE user_id = ? ORDER BY sort_order ASC, id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function getGalleryCount($userId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM faculty_gallery WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
    
    public function addGalleryPhoto($userId, $filename, $caption = '') {
        $count = $this->getGalleryCount($userId);
        if ($count >= 12) return false;
        $stmt = $this->db->prepare("INSERT INTO faculty_gallery (user_id, filename, caption, sort_order) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $filename, $caption, $count]);
    }
    
    public function deleteGalleryPhoto($userId, $id) {
        // Get filename first to delete file
        $stmt = $this->db->prepare("SELECT filename FROM faculty_gallery WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $photo = $stmt->fetch();
        if ($photo) {
            $filepath = __DIR__ . '/../assets/uploads/gallery/' . $photo['filename'];
            if (file_exists($filepath)) unlink($filepath);
        }
        $stmt = $this->db->prepare("DELETE FROM faculty_gallery WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }


    // --- Documents ---
    
    public function getDocuments($userId, $visibility = null) {
        $sql = "SELECT * FROM faculty_documents WHERE user_id = ?";
        $params = [$userId];
        if ($visibility !== null) {
            $sql .= " AND visibility = ?";
            $params[] = $visibility;
        }
        $sql .= " ORDER BY id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function addDocument($userId, $data) {
        $stmt = $this->db->prepare("INSERT INTO faculty_documents (user_id, document_name, filename, visibility) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $data['document_name'], $data['filename'], $data['visibility']]);
    }
    
    public function deleteDocument($userId, $id) {
        $stmt = $this->db->prepare("DELETE FROM faculty_documents WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
    
    // --- Profile Completion ---
    
    public function calculateProfileCompletion($userId) {
        $score = 0;
        
        // 1. Base User Info (Avatar/Basic details) - 20%
        $stmt = $this->db->prepare("SELECT profile_photo, mobile, designation FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            if (!empty($user['profile_photo'])) $score += 10;
            if (!empty($user['mobile']) && !empty($user['designation'])) $score += 10;
        }
        
        // 2. Profile Bio - 10%
        $profile = $this->getProfile($userId);
        if (!empty($profile['bio'])) $score += 10;
        
        // 3. Education - 20%
        $edu = $this->getEducation($userId);
        if (count($edu) > 0) $score += 20;
        
        // 4. Documents (Public vs Private) - 50%
        $docs = $this->getDocuments($userId);
        $hasPublic = false;
        $hasPrivate = false;
        foreach ($docs as $doc) {
            if ($doc['visibility'] === 'public') $hasPublic = true;
            if ($doc['visibility'] === 'private') $hasPrivate = true;
        }
        if ($hasPrivate) $score += 25; // Important private docs like Aadhaar, Marksheet
        if ($hasPublic) $score += 25;  // Public docs like FDP, Awards
        
        return $score;
    }
}
?>
