<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema for the ticketing domain (docs/MODELE.md v2.0, 2026-11-09):
 * venue & seating (Venue, Room, Place, SeatingLayout, LayoutZone,
 * LayoutPlace), programming (Event, Performance), pricing (Tariff,
 * PerformancePrice), inventory (PerformancePlace), reservations
 * (Reservation, ReservationItem), payment (Payment, Refund), ticketing
 * (Ticket), identity (User, BookingPolicy) and integration (OutboxMessage).
 * Uniqueness and foreign keys are in the migration (BR-01, INV-01…).
 */
final class Version20261109215313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking_policy (id BIGINT AUTO_INCREMENT NOT NULL, hold_duration_minutes INT NOT NULL, updated_at DATETIME NOT NULL, updated_by_id BIGINT DEFAULT NULL, INDEX IDX_4BE73EE8896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event (id BIGINT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE layout_place (id BIGINT AUTO_INCREMENT NOT NULL, display_order INT UNSIGNED NOT NULL, is_available_in_layout TINYINT NOT NULL, seating_layout_id BIGINT NOT NULL, layout_zone_id BIGINT NOT NULL, place_id BIGINT NOT NULL, INDEX idx_layout_place_zone (layout_zone_id), UNIQUE INDEX uk_layout_place_layout_seat (seating_layout_id, place_id), INDEX IDX_FD213B07A60119E4 (seating_layout_id), INDEX IDX_FD213B07DA6A219 (place_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE layout_zone (id BIGINT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, seating_layout_id BIGINT NOT NULL, UNIQUE INDEX uk_layout_zone_layout_code (seating_layout_id, code), INDEX IDX_4FA8EA9EA60119E4 (seating_layout_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE outbox_message (id BIGINT AUTO_INCREMENT NOT NULL, event_type VARCHAR(64) NOT NULL, aggregate_type VARCHAR(32) NOT NULL, aggregate_id BIGINT NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL, attempts INT UNSIGNED NOT NULL, next_attempt_at DATETIME DEFAULT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, idempotency_key VARCHAR(64) NOT NULL, reservation_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_4F9D28A67FD1C147 (idempotency_key), INDEX idx_outbox_status_next_attempt (status, next_attempt_at), INDEX IDX_4F9D28A6B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment (id BIGINT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, method VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, provider_reference VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, reservation_id BIGINT NOT NULL, INDEX idx_payment_reservation_status (reservation_id, status), INDEX IDX_6D28840DB83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE performance (id BIGINT AUTO_INCREMENT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, sales_open_at DATETIME DEFAULT NULL, sales_close_at DATETIME DEFAULT NULL, status VARCHAR(20) NOT NULL, event_id BIGINT NOT NULL, seating_layout_id BIGINT NOT NULL, INDEX idx_performance_starts_at (starts_at), INDEX IDX_82D7968171F7E88B (event_id), INDEX IDX_82D79681A60119E4 (seating_layout_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE performance_place (id BIGINT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, hold_expires_at DATETIME DEFAULT NULL, blocked_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, performance_id BIGINT NOT NULL, layout_place_id BIGINT NOT NULL, current_reservation_id BIGINT DEFAULT NULL, UNIQUE INDEX uk_performance_place_seat (performance_id, layout_place_id), INDEX IDX_2CBDE452B91ADEEE (performance_id), INDEX IDX_2CBDE45213D52C2E (layout_place_id), INDEX IDX_2CBDE452F627EA09 (current_reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE performance_price (id BIGINT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, version INT UNSIGNED NOT NULL, is_current TINYINT NOT NULL, created_at DATETIME NOT NULL, performance_id BIGINT NOT NULL, layout_zone_id BIGINT NOT NULL, tariff_id BIGINT NOT NULL, INDEX idx_performance_price_current (performance_id, tariff_id, is_current), UNIQUE INDEX uk_performance_price_combo (performance_id, layout_zone_id, tariff_id, version), INDEX IDX_92689546B91ADEEE (performance_id), INDEX IDX_92689546ED506039 (layout_zone_id), INDEX IDX_9268954692348FD2 (tariff_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place (id BIGINT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, type VARCHAR(20) NOT NULL, row_label VARCHAR(8) DEFAULT NULL, seat_number INT UNSIGNED DEFAULT NULL, is_active TINYINT NOT NULL, room_id BIGINT NOT NULL, UNIQUE INDEX uk_place_room_code (room_id, code), INDEX IDX_741D53CD54177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refund (id BIGINT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, status VARCHAR(20) NOT NULL, reason VARCHAR(255) DEFAULT NULL, provider_reference VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, payment_id BIGINT NOT NULL, INDEX IDX_5B2C14584C3A3BB (payment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id BIGINT AUTO_INCREMENT NOT NULL, reference VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, channel VARCHAR(20) NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, performance_id BIGINT NOT NULL, created_by_id BIGINT NOT NULL, customer_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_42C84955AEA34913 (reference), INDEX idx_reservation_status_expires (status, expires_at), INDEX IDX_42C84955B91ADEEE (performance_id), INDEX IDX_42C84955B03A8386 (created_by_id), INDEX IDX_42C849559395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_item (id BIGINT AUTO_INCREMENT NOT NULL, tariff_name_snapshot VARCHAR(100) NOT NULL, unit_price_snapshot NUMERIC(10, 2) NOT NULL, line_total_snapshot NUMERIC(10, 2) NOT NULL, currency_snapshot VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, eligibility_verified TINYINT NOT NULL, created_at DATETIME NOT NULL, reservation_id BIGINT NOT NULL, performance_place_id BIGINT NOT NULL, performance_price_id BIGINT NOT NULL, UNIQUE INDEX uk_reservation_item_seat (reservation_id, performance_place_id), INDEX IDX_922E876B83297E7 (reservation_id), INDEX IDX_922E87686BBC61B (performance_place_id), INDEX IDX_922E8765D09A3E5 (performance_price_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE room (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, venue_id BIGINT NOT NULL, INDEX IDX_729F519B40A73EBA (venue_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seating_layout (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, version INT UNSIGNED NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, room_id BIGINT NOT NULL, UNIQUE INDEX uk_seating_layout_room_version (room_id, version), INDEX IDX_947D083354177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tariff (id BIGINT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, name VARCHAR(100) NOT NULL, requires_eligibility_proof TINYINT DEFAULT 0 NOT NULL, is_active TINYINT NOT NULL, UNIQUE INDEX UNIQ_9465207D77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket (id BIGINT AUTO_INCREMENT NOT NULL, ticket_number VARCHAR(32) NOT NULL, qr_token VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, issued_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, reservation_item_id BIGINT NOT NULL, UNIQUE INDEX UNIQ_97A0ADA3ECD2759F (ticket_number), UNIQUE INDEX UNIQ_97A0ADA31AE26361 (qr_token), UNIQUE INDEX UNIQ_97A0ADA375FAE9DB (reservation_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id BIGINT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(30) DEFAULT NULL, roles JSON NOT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE venue (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, address VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE booking_policy ADD CONSTRAINT FK_4BE73EE8896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE layout_place ADD CONSTRAINT FK_FD213B07A60119E4 FOREIGN KEY (seating_layout_id) REFERENCES seating_layout (id)');
        $this->addSql('ALTER TABLE layout_place ADD CONSTRAINT FK_FD213B07ED506039 FOREIGN KEY (layout_zone_id) REFERENCES layout_zone (id)');
        $this->addSql('ALTER TABLE layout_place ADD CONSTRAINT FK_FD213B07DA6A219 FOREIGN KEY (place_id) REFERENCES place (id)');
        $this->addSql('ALTER TABLE layout_zone ADD CONSTRAINT FK_4FA8EA9EA60119E4 FOREIGN KEY (seating_layout_id) REFERENCES seating_layout (id)');
        $this->addSql('ALTER TABLE outbox_message ADD CONSTRAINT FK_4F9D28A6B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE performance ADD CONSTRAINT FK_82D7968171F7E88B FOREIGN KEY (event_id) REFERENCES event (id)');
        $this->addSql('ALTER TABLE performance ADD CONSTRAINT FK_82D79681A60119E4 FOREIGN KEY (seating_layout_id) REFERENCES seating_layout (id)');
        $this->addSql('ALTER TABLE performance_place ADD CONSTRAINT FK_2CBDE452B91ADEEE FOREIGN KEY (performance_id) REFERENCES performance (id)');
        $this->addSql('ALTER TABLE performance_place ADD CONSTRAINT FK_2CBDE45213D52C2E FOREIGN KEY (layout_place_id) REFERENCES layout_place (id)');
        $this->addSql('ALTER TABLE performance_place ADD CONSTRAINT FK_2CBDE452F627EA09 FOREIGN KEY (current_reservation_id) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE performance_price ADD CONSTRAINT FK_92689546B91ADEEE FOREIGN KEY (performance_id) REFERENCES performance (id)');
        $this->addSql('ALTER TABLE performance_price ADD CONSTRAINT FK_92689546ED506039 FOREIGN KEY (layout_zone_id) REFERENCES layout_zone (id)');
        $this->addSql('ALTER TABLE performance_price ADD CONSTRAINT FK_9268954692348FD2 FOREIGN KEY (tariff_id) REFERENCES tariff (id)');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD54177093 FOREIGN KEY (room_id) REFERENCES room (id)');
        $this->addSql('ALTER TABLE refund ADD CONSTRAINT FK_5B2C14584C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955B91ADEEE FOREIGN KEY (performance_id) REFERENCES performance (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849559395C3F3 FOREIGN KEY (customer_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE reservation_item ADD CONSTRAINT FK_922E876B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE reservation_item ADD CONSTRAINT FK_922E87686BBC61B FOREIGN KEY (performance_place_id) REFERENCES performance_place (id)');
        $this->addSql('ALTER TABLE reservation_item ADD CONSTRAINT FK_922E8765D09A3E5 FOREIGN KEY (performance_price_id) REFERENCES performance_price (id)');
        $this->addSql('ALTER TABLE room ADD CONSTRAINT FK_729F519B40A73EBA FOREIGN KEY (venue_id) REFERENCES venue (id)');
        $this->addSql('ALTER TABLE seating_layout ADD CONSTRAINT FK_947D083354177093 FOREIGN KEY (room_id) REFERENCES room (id)');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA375FAE9DB FOREIGN KEY (reservation_item_id) REFERENCES reservation_item (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking_policy DROP FOREIGN KEY FK_4BE73EE8896DBBDE');
        $this->addSql('ALTER TABLE layout_place DROP FOREIGN KEY FK_FD213B07A60119E4');
        $this->addSql('ALTER TABLE layout_place DROP FOREIGN KEY FK_FD213B07ED506039');
        $this->addSql('ALTER TABLE layout_place DROP FOREIGN KEY FK_FD213B07DA6A219');
        $this->addSql('ALTER TABLE layout_zone DROP FOREIGN KEY FK_4FA8EA9EA60119E4');
        $this->addSql('ALTER TABLE outbox_message DROP FOREIGN KEY FK_4F9D28A6B83297E7');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DB83297E7');
        $this->addSql('ALTER TABLE performance DROP FOREIGN KEY FK_82D7968171F7E88B');
        $this->addSql('ALTER TABLE performance DROP FOREIGN KEY FK_82D79681A60119E4');
        $this->addSql('ALTER TABLE performance_place DROP FOREIGN KEY FK_2CBDE452B91ADEEE');
        $this->addSql('ALTER TABLE performance_place DROP FOREIGN KEY FK_2CBDE45213D52C2E');
        $this->addSql('ALTER TABLE performance_place DROP FOREIGN KEY FK_2CBDE452F627EA09');
        $this->addSql('ALTER TABLE performance_price DROP FOREIGN KEY FK_92689546B91ADEEE');
        $this->addSql('ALTER TABLE performance_price DROP FOREIGN KEY FK_92689546ED506039');
        $this->addSql('ALTER TABLE performance_price DROP FOREIGN KEY FK_9268954692348FD2');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD54177093');
        $this->addSql('ALTER TABLE refund DROP FOREIGN KEY FK_5B2C14584C3A3BB');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955B91ADEEE');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955B03A8386');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849559395C3F3');
        $this->addSql('ALTER TABLE reservation_item DROP FOREIGN KEY FK_922E876B83297E7');
        $this->addSql('ALTER TABLE reservation_item DROP FOREIGN KEY FK_922E87686BBC61B');
        $this->addSql('ALTER TABLE reservation_item DROP FOREIGN KEY FK_922E8765D09A3E5');
        $this->addSql('ALTER TABLE room DROP FOREIGN KEY FK_729F519B40A73EBA');
        $this->addSql('ALTER TABLE seating_layout DROP FOREIGN KEY FK_947D083354177093');
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA375FAE9DB');
        $this->addSql('DROP TABLE booking_policy');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE layout_place');
        $this->addSql('DROP TABLE layout_zone');
        $this->addSql('DROP TABLE outbox_message');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE performance');
        $this->addSql('DROP TABLE performance_place');
        $this->addSql('DROP TABLE performance_price');
        $this->addSql('DROP TABLE place');
        $this->addSql('DROP TABLE refund');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE reservation_item');
        $this->addSql('DROP TABLE room');
        $this->addSql('DROP TABLE seating_layout');
        $this->addSql('DROP TABLE tariff');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE venue');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
