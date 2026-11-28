<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('ANERP audit-event immutability requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION anerp_reject_audit_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'ANERP audit_events is append-only; UPDATE and DELETE are prohibited';
            END;
            $$;

            CREATE TRIGGER anerp_audit_events_no_update
            BEFORE UPDATE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION anerp_reject_audit_event_mutation();

            CREATE TRIGGER anerp_audit_events_no_delete
            BEFORE DELETE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION anerp_reject_audit_event_mutation();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS anerp_audit_events_no_update ON audit_events;
            DROP TRIGGER IF EXISTS anerp_audit_events_no_delete ON audit_events;
            DROP FUNCTION IF EXISTS anerp_reject_audit_event_mutation();
        SQL);
    }
};
