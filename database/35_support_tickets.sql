-- Support tickets (Retail support.php parity). HO database.
-- Client 1 is the support admin: sees all tickets, updates status / remark / attachments.
-- Every other client sees and chats on its own tickets only.

CREATE TABLE IF NOT EXISTS support_tickets (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_no         VARCHAR(40)  NOT NULL,
    client_id         INT UNSIGNED NOT NULL,
    branch_id         INT UNSIGNED NULL,
    user_id           INT UNSIGNED NULL,
    medical_name      VARCHAR(255) NOT NULL DEFAULT '',
    contact_no        VARCHAR(32)  NOT NULL DEFAULT '',
    created_by        VARCHAR(150) NOT NULL DEFAULT '',
    subject           VARCHAR(500) NOT NULL DEFAULT '',
    query_text        TEXT         NOT NULL,
    screenshots       TEXT         NULL,
    status            VARCHAR(32)  NOT NULL DEFAULT 'In Process',
    admin_remark      TEXT         NULL,
    admin_screenshots TEXT         NULL,
    admin_files       TEXT         NULL,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    status_changed_at DATETIME     NULL,
    UNIQUE KEY uk_support_ticket_no (ticket_no),
    KEY idx_support_client (client_id),
    KEY idx_support_status (status),
    KEY idx_support_created (created_at),
    KEY idx_support_status_changed (status_changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_replies (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT UNSIGNED NOT NULL,
    client_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NULL,
    sender_name VARCHAR(150) NOT NULL DEFAULT '',
    is_admin    TINYINT(1)   NOT NULL DEFAULT 0,
    message     TEXT         NOT NULL,
    screenshots TEXT         NULL,
    files       TEXT         NULL,
    created_at  DATETIME     NOT NULL,
    KEY idx_reply_ticket (ticket_id),
    KEY idx_reply_created (created_at),
    CONSTRAINT fk_support_reply_ticket FOREIGN KEY (ticket_id)
        REFERENCES support_tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
