<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payments, payment_attempts, refunds, saved_payment_methods tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE payments (
                id                       VARCHAR(36)      NOT NULL,
                amount_minor             BIGINT           NOT NULL,
                amount_currency_code     VARCHAR(10)      NOT NULL,
                amount_currency_precision SMALLINT        NOT NULL,
                customer_id              VARCHAR(255)     NOT NULL,
                status                   VARCHAR(30)      NOT NULL,
                successful_attempt_id    VARCHAR(36)      DEFAULT NULL,
                metadata                 JSONB            NOT NULL DEFAULT \'[]\',
                created_at               TIMESTAMP(0)     NOT NULL,
                expires_at               TIMESTAMP(0)     DEFAULT NULL,
                refunded_amount_minor    BIGINT           NOT NULL DEFAULT 0,
                version                  INTEGER          NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        ');

        $this->addSql('CREATE INDEX idx_payment_customer_id ON payments (customer_id)');
        $this->addSql('CREATE INDEX idx_payment_status ON payments (status)');

        $this->addSql('
            CREATE TABLE payment_attempts (
                id                        VARCHAR(36)      NOT NULL,
                payment_id                VARCHAR(36)      NOT NULL,
                provider_name             VARCHAR(100)     NOT NULL,
                amount_minor              BIGINT           NOT NULL,
                amount_currency_code      VARCHAR(10)      NOT NULL,
                amount_currency_precision SMALLINT         NOT NULL,
                provider_reference        VARCHAR(255)     DEFAULT NULL,
                status                    VARCHAR(30)      NOT NULL,
                provider_status           VARCHAR(100)     NOT NULL,
                created_at                TIMESTAMP(0)     NOT NULL,
                method_type               VARCHAR(20)      NOT NULL,
                data                      JSONB            NOT NULL DEFAULT \'{}\',
                version                   INTEGER          NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        ');

        $this->addSql('CREATE INDEX idx_attempt_payment_id ON payment_attempts (payment_id)');
        $this->addSql('CREATE UNIQUE INDEX idx_attempt_provider_reference ON payment_attempts (provider_name, provider_reference) WHERE provider_reference IS NOT NULL');

        $this->addSql('
            CREATE TABLE refunds (
                id                        VARCHAR(36)      NOT NULL,
                payment_id                VARCHAR(36)      NOT NULL,
                original_attempt_id       VARCHAR(36)      NOT NULL,
                provider_name             VARCHAR(100)     NOT NULL,
                amount_minor              BIGINT           NOT NULL,
                amount_currency_code      VARCHAR(10)      NOT NULL,
                amount_currency_precision SMALLINT         NOT NULL,
                provider_reference        VARCHAR(255)     DEFAULT NULL,
                status                    VARCHAR(30)      NOT NULL,
                provider_status           VARCHAR(100)     NOT NULL,
                created_at                TIMESTAMP(0)     NOT NULL,
                method_type               VARCHAR(20)      NOT NULL,
                data                      JSONB            NOT NULL DEFAULT \'{}\',
                version                   INTEGER          NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        ');

        $this->addSql('CREATE INDEX idx_refund_payment_id ON refunds (payment_id)');
        $this->addSql('CREATE UNIQUE INDEX idx_refund_provider_reference ON refunds (provider_name, provider_reference) WHERE provider_reference IS NOT NULL');

        $this->addSql('
            CREATE TABLE saved_payment_methods (
                id             VARCHAR(36)      NOT NULL,
                customer_id    VARCHAR(255)     NOT NULL,
                provider_name  VARCHAR(100)     NOT NULL,
                provider_token VARCHAR(500)     NOT NULL,
                status         VARCHAR(30)      NOT NULL,
                created_at     TIMESTAMP(0)     NOT NULL,
                method_type    VARCHAR(20)      NOT NULL,
                data           JSONB            NOT NULL DEFAULT \'{}\',
                version        INTEGER          NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        ');

        $this->addSql('CREATE INDEX idx_spm_customer_id ON saved_payment_methods (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX idx_spm_provider_token ON saved_payment_methods (provider_name, provider_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE saved_payment_methods');
        $this->addSql('DROP TABLE refunds');
        $this->addSql('DROP TABLE payment_attempts');
        $this->addSql('DROP TABLE payments');
    }
}
