<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A PDF with boxes drawn over it, and the people asked to fill them in.
     *
     * Four tables, and the shape to notice is that the document, the questions
     * and the answers are three separate things. A contract holds the bytes
     * somebody uploaded; the fields are what the author drew over them; the
     * values are what each signer put in each box. Keeping them apart is what
     * lets one PDF be signed by three people who each fill in their own half.
     *
     * The coordinates are the other decision worth reading. Every x, y, width
     * and height here is a fraction of the page — 0 at the left or top edge, 1
     * at the right or bottom — never a pixel. The editor renders through pdf.js
     * at whatever scale the screen allows and the public page does the same on
     * a phone, so a box stored in pixels would sit half a page off for the
     * person actually signing it. Multiplying by the real page size happens at
     * the last possible moment, in the renderer.
     *
     * decimal rather than float, because these survive a round trip through
     * JSON and back on every save in the editor, and a coordinate that drifts
     * in the eighth decimal on each save is a box that walks down the page over
     * an afternoon of editing.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            // ULID like Transfer, Form and SecretRequest: it lands in a
            // conversation and its id travels in a URL.
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * The author keeps the contract alive after they leave, the way a
             * form outlives its maker. A signed contract is evidence, and
             * evidence that disappears when somebody changes jobs is not
             * evidence — which is why this is nullOnDelete rather than cascade.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');

            // What the author writes in the invitation mail. Not part of the
            // document — nothing here is ever printed onto the PDF.
            $table->text('message')->nullable();

            $table->string('status', 20)->default('draft');

            /*
             * How many pages the PDF turned out to have, counted once at upload.
             *
             * Stored rather than parsed on demand because both the editor and
             * every signer's page need it before they can lay anything out, and
             * parsing a PDF to learn a single number on every page load is a
             * cost paid over and over for an answer that cannot change.
             */
            $table->unsignedSmallInteger('page_count')->default(0);

            /*
             * sha256 over the stored PDF, and the quiet backbone of the whole
             * feature.
             *
             * Written when the file is stored and checked again at signing, so
             * that "getekend onder dit document" is a claim with something
             * behind it rather than a hope. Nullable only for the moment
             * between the row being created and the file landing on it.
             */
            $table->char('source_hash', 64)->nullable();

            /*
             * When the links stop working. Nullable, meaning no deadline —
             * uncommon on a contract but not something to forbid, and null is
             * how the prune command knows to leave it alone.
             */
            $table->timestamp('expires_at')->nullable();

            /*
             * The three endings, each with its own stamp.
             *
             * Kept as separate columns beside the status rather than folded into
             * it, because "wanneer" is asked as often as "wat": the overview
             * sorts on them and the audit trail prints them. The status column
             * stays the single thing anything branches on.
             */
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('contract_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('contract_id')->constrained()->cascadeOnDelete();

            // 1-based, because that is what a person calls the first page and
            // what pdf.js asks for. Nothing here ever counts from zero.
            $table->unsignedSmallInteger('page');

            /*
             * The box, as a fraction of the page. See the note at the top.
             *
             * decimal(9, 8) holds 0.00000000 to 1.00000000 with room to spare:
             * eight decimals is about a thousandth of a millimetre on A4, which
             * is far finer than anybody can drag and far coarser than float
             * drift.
             */
            $table->decimal('x', 9, 8);
            $table->decimal('y', 9, 8);
            $table->decimal('width', 9, 8);
            $table->decimal('height', 9, 8);

            $table->string('type', 20);
            $table->string('label');
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            /*
             * Which signer fills this one in, by their place in the list.
             *
             * An index rather than a foreign key, and deliberately: fields are
             * drawn before the signers are named, so the author is saying "de
             * tweede ondertekenaar" about somebody who does not exist yet. The
             * signers table carries the same number in signing_order, which is
             * what ties the two together once the invitations go out.
             *
             * Null means the first signer — the ordinary case of a contract
             * with one person on it, where making the author choose would be
             * asking a question with one answer.
             */
            $table->unsignedSmallInteger('signer_index')->nullable();

            $table->index(['contract_id', 'page']);
        });

        Schema::create('contract_signers', function (Blueprint $table) {
            /*
             * ULID rather than an auto-increment id, and for one reason: the
             * drawn signature hangs on this row through the media library, and
             * the media table is keyed with ulidMorphs — see its migration,
             * where the same thing was decided for messages. A bigint here
             * would be silently stringified into a char(26) column, which works
             * until the day somebody writes a join.
             */
            $table->ulid('id')->primary();
            $table->foreignUlid('contract_id')->constrained()->cascadeOnDelete();

            /*
             * Set when the signer is a colleague picked from the workspace,
             * null when they are an address typed in by hand. What it buys is
             * that the chat card and the inbox can address them as a person
             * rather than as an email — the outside world stays reachable
             * either way, because the token is what actually opens the door.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('email');

            /*
             * The whole of the credential, one per signer.
             *
             * Separate per person rather than one link for the contract, which
             * is the same decision transfer_recipients made and for a stronger
             * reason here: a shared link could not say who signed, and who
             * signed is the only thing a contract is for.
             */
            $table->string('token', 64)->unique();

            /*
             * Where this signer stands in the queue, and the twin of
             * signer_index on the fields above.
             *
             * Sequential signing — first A, then B — is out of scope for now
             * and everybody gets their link at once. The column is here anyway
             * because retrofitting an order onto contracts that already exist
             * means guessing at one.
             *
             * Named signing_order rather than order: "order" is a reserved word
             * in SQL, and while the query builder quotes it, every raw fragment
             * anybody ever writes against this table would have to remember to.
             */
            $table->unsignedSmallInteger('signing_order')->default(0);

            /*
             * The three moments, and the two facts about the browser.
             *
             * opened_at is stamped the first time the link is followed, which
             * is worth having on its own: "hij heeft het niet eens geopend" and
             * "hij heeft het gezien en niets gedaan" are different conversations
             * for the person waiting.
             *
             * declined_at is an outcome, not a failure — somebody may read a
             * contract and say no, and the feature has to have a word for that
             * other than silence.
             */
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();

            /*
             * Recorded at the moment of signing and never before: this is the
             * audit trail, not analytics. 45 characters because that is what an
             * IPv6 address with an embedded IPv4 tail needs.
             */
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            // The same address twice on one contract is two links to one inbox,
            // and two rows claiming to be the person who signed.
            $table->unique(['contract_id', 'email']);
        });

        Schema::create('contract_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_field_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contract_signer_id')->constrained()->cascadeOnDelete();

            /*
             * What was typed, or null when nothing was.
             *
             * Also null for a signature or a set of initials: those are drawn,
             * and the image lives on the signer rather than here — one drawing
             * reused across every box of that kind, so that a contract wanting
             * initials on nine pages does not ask somebody to draw nine times.
             * See ContractSigner's media collections. What this row then carries
             * is filled_at: the fact that the box was dealt with.
             */
            $table->text('value')->nullable();

            $table->timestamp('filled_at')->nullable();

            /*
             * One answer per box per person. A constraint rather than a
             * convention, because the public page saves drafts as somebody
             * types and a dropped connection retrying a save must update the
             * row it wrote rather than lay a second one beside it.
             */
            $table->unique(['contract_field_id', 'contract_signer_id'], 'contract_field_values_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_field_values');
        Schema::dropIfExists('contract_signers');
        Schema::dropIfExists('contract_fields');
        Schema::dropIfExists('contracts');
    }
};
