<?php

namespace Tests\Feature\Item;

/**
 * /item page markup — ang sourcing chips + worklist table ay nasa CEO view LANG.
 * Direktang nire-render ang view (ang index() ay nangangailangan ng maraming legacy table).
 */
class ItemPageTest extends ItemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Kailangan ng layout (task badge) — gaya ng BoardroomTestCase.
        \Illuminate\Support\Facades\Schema::create('tasks', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->actingAs($this->user());
    }

    private function render(bool $effectiveIsCEO, bool $layoutOld = false): string
    {
        return view('item.index', [
            'layoutOld' => $layoutOld,
            'pages' => [], 'isCEO' => true, 'isMarketingOIC' => false,
            'viewAs' => $effectiveIsCEO ? 'ceo' : 'marketing', 'effectiveIsCEO' => $effectiveIsCEO,
            'ownerPrivateColsConfig' => null, 'campaignsColsConfig' => null, 'breakevenTargetPct' => 5,
            'colFormatRules' => [], 'campaignsColFormatRules' => [],
            'feeShipping' => null, 'feeCodRate' => null, 'feeVatRate' => null,
        ])->render();
    }

    public function test_old_render_has_the_full_scrolling_table(): void
    {
        $html = $this->render(true, true);
        foreach ([
            "col.id==='doi'", 'Drag headers to reorder', '<td>TOTAL</td>',
            'colDragStart(', 'page-col-header', 'expand-panel',
        ] as $marker) {
            $this->assertStringContainsString($marker, $html);
        }
    }

    public function test_default_render_has_the_new_layout_and_the_old_one_has_the_old_table(): void
    {
        $new = $this->render(true);
        $old = $this->render(true, true);
        foreach (['Drag headers to reorder', 'colDragStart($event', '<td>TOTAL</td>'] as $oldMarker) {
            $this->assertStringNotContainsString($oldMarker, $new);
            $this->assertStringContainsString($oldMarker, $old);
        }
        $this->assertStringContainsString('id="item-layout-new"', $new);
        $this->assertStringNotContainsString('id="item-layout-new"', $old);
        // Link papunta sa kabilang view.
        $this->assertStringContainsString('Lumang view', $new);
        $this->assertStringNotContainsString('Bagong view', $new);
        $this->assertStringContainsString('Bagong view', $old);
        $this->assertStringNotContainsString('Lumang view', $old);
        // Pinapanatili ng load() ang ?layout=old sa URL.
        $this->assertStringContainsString("qsObj.layout = 'old'", $new);
        $this->assertStringContainsString('layoutOld: false,', $new);
        $this->assertStringContainsString('layoutOld: true,', $old);
    }

    // ── Bagong layout (005 T3): main row ──────────────────────────────────────

    public function test_new_table_has_the_eleven_headers_in_order_with_sort_keys(): void
    {
        foreach ([$this->render(true), $this->render(false)] as $html) {
            $last = 0;
            foreach (['ITEM', 'LIFECYCLE', 'STOCK / PAPARATING', 'BENTA/ARAW', 'AABOT PA?', 'I-ORDER',
                      'KITA NGAYON', 'KITA %', 'ADS', 'ACTION'] as $label) {
                // BENTA/ARAW: puwedeng mag-break pagkatapos ng slash (hindi sa gitna ng salita).
                $shown = $label === 'BENTA/ARAW' ? 'BENTA/<wbr>ARAW' : $label;
                $pos = strpos($html, '<span>' . $shown . '</span>', $last);
                $this->assertNotFalse($pos, "header {$label} missing or out of order");
                $last = $pos;
            }
            $this->assertGreaterThan($last, strpos($html, 'il-chev-th', $last));
            foreach (['item_name', 'lifecycle', 'stock', 'units_per_day', 'doi', 'order_qty',
                      'projected_profit_last_day', 'proj_pct_last_7d', 'adspent', 'il_action_at'] as $key) {
                $this->assertStringContainsString("sb('{$key}')", $html);
            }
            // Composite columns: lalabas lang kapag may member id na visible.
            $this->assertStringContainsString('ilColOn(', $html);
            $this->assertStringContainsString('table-layout:fixed', $html);
            $this->assertStringContainsString('<colgroup>', $html);
        }
    }

    public function test_new_row_texts_are_plain_taglish_sentences_without_x_html(): void
    {
        $texts = [
            '🆕 Bago', '📈 Lumalaki', '✅ Stable', '🔄 Aktibo', '📉 Bumababa', '🚫 Itinitigil', '💤 Tulog',
            'Hindi pa nabibilang', 'Naka-hold: ', 'average ng huling ', 'Umorder ', 'Dating sa ', 'araw reserba',
            'Hindi pa alam — bilangin muna ang stock', 'Kulang: ', 'araw na benta ang naka-hold',
            'Mauubos bago dumating', 'Malapit na: ', 'Sapat: ', 'mahigit 1 taon', 'Halos walang benta', 'Walang benta',
            'Bilangin muna ang stock', 'Hindi pa kailangan', 'Hanap muna ng supplier', 'ngayon na', "'bago '",
            "['Ene','Peb','Mar','Abr','May','Hun','Hul','Ago','Set','Okt','Nob','Dis']", '−₱', 'HOLD lang',
            'naka-hold − ', 'No data for selected date.', 'Walang item sa category na ito.',
        ];
        foreach ([$this->render(true), $this->render(false)] as $html) {
            foreach ($texts as $text) {
                $this->assertStringContainsString($text, $html);
            }
            $this->assertSame(0, substr_count($html, 'x-html'));
        }
    }

    public function test_new_row_ceo_only_parts_are_absent_for_marketing(): void
    {
        $ceoOnly = ['ilPieceCost(G.item_name', '≈ ', 'openStockEdit(G.item_name', 'openQuote(G.item_name',
                    'wala pang supplier', 'blank = default ng lifecycle', 'saveSupplySettings(G.item_name'];
        $ceo = $this->render(true);
        $mkt = $this->render(false);
        foreach ($ceoOnly as $s) {
            $this->assertStringContainsString($s, $ceo);
            $this->assertStringNotContainsString($s, $mkt);
        }
        // Marketing: lead line plain text lang (walang ✎ button).
        $this->assertStringContainsString('ilLeadLine(G.item_name)', $mkt);
    }

    // ── Bagong layout (005 T4): expanded block ────────────────────────────────

    public function test_expanded_block_has_item_section_page_cards_and_actions(): void
    {
        foreach ([$this->render(true), $this->render(false)] as $html) {
            foreach ([
                'Puhunan bawat piraso', 'Halaga ng isang piraso (cogs) hanggang sa huling petsa ng range',
                'Paano nakuha: ', 'Mga page (', 'Walang running page — walang page na maipapakita.',
                'Campaigns', 'Ipakita/itago ang campaigns ng page na ito',
                'togglePageExpand(row.page_name)', 'class="il-camp"', 'expand-panel',
                'x-for="row in G.pages"',
                "openEditModal(row, 'rts')", "openEditModal(row, 'promo')", "openEditModal(row, 'cogs')",
                'openActionModal(row)', 'openBreakdown(row)',
                'copyItem(G.item_name', "itemImages[G.item_name] ? 'Change' : 'Add photo'",
                'il-only-lt1366', 'il-only-lt1100',
            ] as $s) {
                $this->assertStringContainsString($s, $html);
            }
            $this->assertSame(0, substr_count($html, 'x-html'));
        }
    }

    public function test_ceo_piece_cost_category_editor_and_ceo_cogs_edit_are_absent_for_marketing(): void
    {
        $ceoOnly = ["'CEO: ' + ilMoney(", "openEditModal(row, 'cogs_ceo')", 'saveCategory(G.item_name', '+ bagong category'];
        $ceo = $this->render(true);
        $mkt = $this->render(false);
        foreach ($ceoOnly as $s) {
            $this->assertStringContainsString($s, $ceo);
            $this->assertStringNotContainsString($s, $mkt);
        }
    }

    public function test_campaigns_panel_is_scoped_to_fit_without_horizontal_scroll(): void
    {
        $html = $this->render(true);
        foreach ([
            '.il-camp .expand-wrap{overflow:visible !important; max-height:none !important;', '.il-camp .fb-table{table-layout:fixed;width:100%;',
            'overflow-wrap:anywhere', '.il-camp .fb-table *{white-space:normal !important;}',
            '.il-expand{min-width:0;', '.il-page-card{min-width:0;',
        ] as $css) {
            $this->assertStringContainsString($css, $html);
        }
        // Walang inner scroll: walang overflow-y:auto / max-height:70vh / overflow-x:hidden sa .il-camp rules.
        preg_match_all('/\.il-camp[^{]*\{[^}]*\}/', $html, $rules);
        $campCss = implode("\n", $rules[0]);
        $this->assertNotSame('', $campCss);
        foreach (['overflow-y:auto', 'max-height:70vh', 'overflow-x:hidden', 'overflow:auto', 'overflow:hidden'] as $bad) {
            $this->assertStringNotContainsString($bad, $campCss);
        }
        // Ang /owner/private ay hindi ginalaw: walang .il-camp doon.
        $this->assertStringNotContainsString('il-camp', file_get_contents(resource_path('views/owner/private.blade.php')));
        $this->assertStringNotContainsString('il-camp', file_get_contents(resource_path('views/owner/_private_expand_inline.blade.php')));
    }

    public function test_expanded_block_has_no_cell_fills_and_new_action_header_sorts_by_latest_note(): void
    {
        foreach (['item/_il_expand.blade.php', 'item/_table_new.blade.php'] as $f) {
            $src = file_get_contents(resource_path('views/' . $f));
            foreach (['pbStyle(', 'pbStyleN(', 'cellFormatStyle(', 'background:#fef2f2'] as $fill) {
                $this->assertStringNotContainsString($fill, $src, "{$f} has {$fill}");
            }
        }
        $html = $this->render(true);
        $this->assertStringContainsString("sb('il_action_at')", $html);
        $this->assertStringContainsString("case 'il_action_at':", $html);
        // Tulad ng ilLatestAction: pages na may action_comment lang ang binibilang.
        $this->assertStringContainsString('if (r.action_comment && r.action_at && (best', $html);
    }

    public function test_new_layout_review_minors_are_in_the_markup(): void
    {
        $html = $this->render(true);
        $expand = file_get_contents(resource_path('views/item/_il_expand.blade.php'));
        $table = file_get_contents(resource_path('views/item/_table_new.blade.php'));
        foreach ([
            // 1: link ay binubuo sa click time, hindi lang sa render
            '@click.prevent="window.location.href = viewSwitchUrl()"',
            // 2: round muna sa isang decimal bago magdesisyon
            'const n = Number(x), r1 = Math.round(n * 10) / 10;',
            // 3: cover galing sa numero ng server
            'Number(P.order_qty) - hold + inc + st',
            // 6: HOLD tooltip ng page card
            "'wala pang hold snapshot'",
            // 7: STOCK cell puwedeng mag-wrap
            '.il-table .il-wrap { white-space:normal; overflow-wrap:normal; }',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // 5: walang "0.0" kapag null ang units_per_day (main row at narrow duplicate)
        foreach ([$table, $expand] as $src) {
            $this->assertStringContainsString('units_per_day != null) ? Number(', $src);
        }
        $this->assertStringNotContainsString('il-nb" x-text="\'Paparating', $table);
    }

    public function test_default_item_order_is_by_urgency_in_the_new_layout_and_by_hold_in_the_old(): void
    {
        $html = $this->render(true);
        $this->assertStringContainsString('if (this.layoutOld) {', $html);
        $this->assertStringContainsString('ilUrgency(', $html);
        $this->assertStringContainsString('out.sort((a, b) => b.hold - a.hold);', $html);
        $this->assertLessThan(strpos($html, 'this.ilUrgency(g)'), strpos($html, 'if (this.layoutOld) {'));
    }

    public function test_hold_and_worklist_load_in_parallel_with_the_item_summary(): void
    {
        $html = $this->render(true);
        $summary = strpos($html, "await fetch('" . route('owner.private.item-summary'));
        $this->assertNotFalse($summary);
        // Nauuna ang hold + worklist sa summary fetch (load() lang ang tumatawag ng hold, isang beses).
        $this->assertLessThan($summary, strpos($html, 'this.loadHold();'));
        $this->assertLessThan($summary, strpos($html, 'this.loadWorklist();'));
        $this->assertSame(1, substr_count($html, 'this.loadHold();'));
        // Sabay-sabay ang images / suppliers / quotes sa init().
        $this->assertStringContainsString('Promise.all([', $html);
        $this->assertStringNotContainsString('await this.loadItemImages();', $html);
    }

    public function test_stock_columns_are_registered_and_rendered_on_item_rows(): void
    {
        $ceo = $this->render(true, true);
        $ids = ['category', 'stock', 'incoming', 'units_per_day', 'doi', 'order_qty'];
        $catalogIds = array_column(\App\Http\Controllers\OwnerColumnSettingsController::CATALOG['owner_private'], 'id');
        $defaultIds = \App\Http\Controllers\OwnerColumnSettingsController::DEFAULT_VISIBLE['owner_private'];
        foreach ($ids as $id) {
            $this->assertStringContainsString("id:'{$id}'", $ceo);                 // defaultCols()
            $this->assertStringContainsString("col.id==='{$id}'", $ceo);           // item-row cell
            $this->assertContains($id, $catalogIds);
            // Handoff 004: nakatago na by default ang CATEGORY (nasa catalog pa rin).
            $id === 'category' ? $this->assertNotContains($id, $defaultIds) : $this->assertContains($id, $defaultIds);
        }
        $this->assertStringContainsString("col.id==='item_val'", $ceo);
        $this->assertStringContainsString('itemValue(row.item_name)', $ceo);
        // Walang x-html sa bagong cells (x-text lang).
        $this->assertSame(0, substr_count($ceo, 'x-html'));
        // Tooltip + marker texts.
        $this->assertStringContainsString('bilangin', $ceo);
        $this->assertStringContainsString('kulang ', $ceo);
    }

    public function test_lifecycle_column_is_registered_and_rendered_on_item_rows(): void
    {
        $ctl = \App\Http\Controllers\OwnerColumnSettingsController::class;
        $this->assertContains('lifecycle', array_column($ctl::CATALOG['owner_private'], 'id'));
        $this->assertContains('lifecycle', $ctl::DEFAULT_VISIBLE['owner_private']);
        foreach ([$this->render(true, true), $this->render(false, true)] as $html) {
            $this->assertStringContainsString("id:'lifecycle'", $html);              // defaultCols()
            $this->assertStringContainsString("col.id==='lifecycle'", $html);        // item-row cell
            $this->assertStringContainsString("'order_qty','lifecycle']", $html);    // labas sa catch-all na blank cell
            $this->assertStringContainsString("case 'lifecycle':", $html);           // sort by label
            $this->assertStringContainsString('lifecycle_label', $html);
            $this->assertStringContainsString("' · lugi'", $html);
            $this->assertStringContainsString('(manual)', $html);
            $this->assertStringContainsString('title="Ikaw ang nagtakda ng lifecycle na ito sa Supply page; hindi awtomatiko"', $html);
            $this->assertStringContainsString('HOLD lang ang i-order', $html);
            $this->assertSame(0, substr_count($html, 'x-html'));
        }
    }

    public function test_rows_pick_the_normal_or_lugi_set_from_the_combined_base_profit(): void
    {
        foreach ([$this->render(true, true), $this->render(false, true)] as $html) {
            // Isang profit figure kada base item: Σ projected_profit_last_7d ÷ Σ gross_sales_last_7d.
            $this->assertStringContainsString('baseProfitPct7(name)', $html);
            $this->assertStringContainsString('S.gated && pct != null && pct <= 0', $html);
            $this->assertStringContainsString('this.stockIsLugi(name) ? S.lugi : S.normal', $html);
            // Benta/araw, DOI, I-order at order-by ay galing sa napiling set.
            $this->assertGreaterThanOrEqual(4, substr_count($html, 'stockSet(row.item_name)'));
            $this->assertStringContainsString("case 'units_per_day': case 'doi': case 'order_qty':", $html);
            $this->assertStringContainsString('walang benta', $html);
            $this->assertStringContainsString('halos walang benta', $html);
            $this->assertStringContainsString('HOLD lang', $html);
            $this->assertStringContainsString('huling 7 araw', $html);
        }
        // Ang palugit editor ay CEO lang.
        $this->assertStringContainsString('blank = default ng lifecycle', $this->render(true, true));
        $this->assertStringNotContainsString('blank = default ng lifecycle', $this->render(false, true));
    }

    public function test_category_selector_shows_only_while_the_category_column_is_visible(): void
    {
        $ctl = \App\Http\Controllers\OwnerColumnSettingsController::class;
        $this->assertNotContains('category', $ctl::DEFAULT_VISIBLE['owner_private']);
        foreach ([$this->render(true, true), $this->render(false, true)] as $html) {
            $this->assertStringContainsString('x-show="categoryColVisible()"', $html);
            $this->assertStringContainsString("cols.some(c => c.id === 'category')", $html);
            $this->assertStringContainsString("categoryFilter !== '' && categoryColVisible()", $html);
            $this->assertStringContainsString("if (f === '' || !this.categoryColVisible()", $html);
            $this->assertStringContainsString("if (!this.categoryColVisible()) this.categoryFilter = '';", $html);
        }
    }

    public function test_order_tooltip_is_hold_only_for_phasing_out_and_dormant_without_palugit(): void
    {
        foreach ([$this->render(true, true), $this->render(false, true)] as $html) {
            $this->assertStringContainsString('ilang piraso ang dapat i-order (HOLD lang − stock − paparating; walang benta kaya walang dagdag)', $html);
            $this->assertStringContainsString('ilang piraso ang dapat i-order (HOLD + benta habang hinihintay − stock − paparating)', $html);
            $this->assertStringContainsString('orderTip(row.item_name)', $html);
        }
    }

    public function test_stock_loads_in_parallel_before_the_item_summary(): void
    {
        $html = $this->render(true);
        $summary = strpos($html, "await fetch('" . route('owner.private.item-summary'));
        $this->assertLessThan($summary, strpos($html, 'this.loadStock();'));
        $this->assertSame(1, substr_count($html, 'this.loadStock();'));
        $this->assertStringContainsString(route('item.stock'), $html);
    }

    public function test_ceo_item_value_binding_is_not_in_the_marketing_render(): void
    {
        $this->assertStringContainsString('itemValueCeo(row.item_name)', $this->render(true, true));
        $this->assertStringNotContainsString('itemValueCeo(row.item_name)', $this->render(false, true));
    }

    public function test_doi_lead_and_order_by_lines_have_plain_tooltips(): void
    {
        $lead = 'Lead = ilang araw bago dumating ang order; palugit = dagdag na araw na reserba';
        $ceo  = $this->render(true, true);
        $mkt  = $this->render(false, true);
        foreach ([$ceo, $mkt] as $html) {
            $this->assertStringContainsString($lead, $html);
            $this->assertStringContainsString('Pula: mauubos bago dumating ang order. Dilaw: malapit na. Berde: ok pa.', $html);
            $this->assertStringContainsString('Abo: walang benta, o mas mababa sa 0.5 kada araw', $html);
            $this->assertStringContainsString('Huling araw na pwedeng umorder para hindi maubusan, base sa lead time', $html);
        }
        $this->assertStringContainsString($lead . ' — i-click para palitan', $ceo);
        $this->assertStringNotContainsString('i-click para palitan', $mkt);
    }

    public function test_category_filter_is_for_every_role_and_inline_edits_are_ceo_only(): void
    {
        $ceo = $this->render(true, true);
        $mkt = $this->render(false, true);
        foreach ([$ceo, $mkt] as $html) {
            $this->assertStringContainsString('x-model="categoryFilter"', $html);
            $this->assertStringContainsString('Walang category', $html);
            $this->assertStringContainsString('Walang item sa category na ito.', $html);
            $this->assertSame(0, substr_count($html, 'x-html'));
            // AND-ed pagkatapos ng worklistKeep sa itemGroups().
            $this->assertGreaterThan(
                strpos($html, 'worklistKeep(u.name, u.hold)'),
                strpos($html, 'categoryKeep(u.name)')
            );
        }
        $this->assertStringContainsString('saveCategory(row.item_name', $ceo);
        $this->assertStringContainsString('saveSupplySettings(row.item_name', $ceo);
        $this->assertStringContainsString('+ bagong category', $ceo);
        foreach (['saveCategory(row.item_name', 'saveSupplySettings(row.item_name', '+ bagong category', 'openStockEdit(row.item_name'] as $s) {
            $this->assertStringNotContainsString($s, $mkt);
        }
        $this->assertStringContainsString('openStockEdit(row.item_name', $ceo);
        // Guards: lead/palugit validation, category-empty row ignores ?list= for non-CEO view.
        $this->assertStringContainsString('Lagyan ng numero (0–255) ang lead at palugit.', $ceo);
        foreach ([$ceo, $mkt] as $html) {
            $this->assertStringContainsString("(!effectiveIsCeo || worklist.list === 'lahat')", $html);
        }
    }

    public function test_sourcing_chips_and_worklist_show_in_the_ceo_view_only(): void
    {
        $ceo = $this->render(true, true);
        $this->assertStringContainsString('setWorklist(c.key)', $ceo);
        $this->assertStringContainsString(route('item.worklist'), $ceo);
        // Isang table lang: ang chips ay filter ng itemGroups(), walang hiwalay na worklist table.
        // ...at HOLD 0 na row (hal. "1 x" na may page lang) ay hindi kasama habang may napiling list.
        $this->assertStringContainsString('worklistKeep(u.name, u.hold)', $ceo);
        $this->assertStringNotContainsString("x-show=\"worklist.list !== 'lahat'\"", $ceo);
        $this->assertStringNotContainsString("x-show=\"!effectiveIsCeo || worklist.list === 'lahat'\"", $ceo);
        $this->assertStringNotContainsString('worklistRows()', $ceo);
        $this->assertStringContainsString('Walang item sa listahang ito.', $ceo);
        // Extra info sa item cell habang may napiling list.
        $this->assertStringContainsString("'kabuuan: '", $ceo);
        $this->assertStringContainsString("'Kulang '", $ceo);

        $mkt = $this->render(false, true);
        $this->assertStringNotContainsString('setWorklist(c.key)', $mkt);
        $this->assertStringNotContainsString("'kabuuan: '", $mkt);
        $this->assertStringNotContainsString("'Kulang '", $mkt);
        $this->assertStringNotContainsString('Walang item sa listahang ito.', $mkt);
    }

    // ── Bagong layout (005 T5): TOTAL, toolbar, breakpoints ───────────────────

    public function test_new_table_total_follows_the_visible_items_and_the_old_total_is_unchanged(): void
    {
        foreach ([true, false] as $ceo) {
            $new = $this->render($ceo);
            $this->assertStringContainsString('class="il-total"', $new);
            $this->assertStringContainsString('TOTAL (nakikita)', $new);
            $this->assertStringContainsString('Kabuuan ng mga item na nakikita ngayon (kasama ang filter)', $new);
            $this->assertStringContainsString('ilTotVisible() { return this.aggOf(this.itemGroups().flatMap(G => G.pages)); }', $new);
            $this->assertStringContainsString('ilHoldVisible() {', $new);
            // Isang compute lang ng total kada render (T), hindi bawat cell.
            $this->assertStringContainsString('x-for="T in [ilTotVisible()]"', $new);
            $this->assertSame(1, substr_count($new, 'ilTotVisible()]'));
            $this->assertStringNotContainsString('<td>TOTAL</td>', $new);

            $old = $this->render($ceo, true);
            $this->assertStringContainsString('<td>TOTAL</td>', $old);
            $this->assertStringContainsString('tot() { return this.aggOf(this.filteredRows()); }', $old);
            $this->assertStringNotContainsString('class="il-total"', $old);
        }
    }

    public function test_i_order_chip_is_renamed_with_a_tooltip(): void
    {
        $ceo = $this->render(true);
        $this->assertStringContainsString("label:'Handa nang i-order (may supplier)'", $ceo);
        $this->assertStringNotContainsString("label:'I-order na'", $ceo);
        $this->assertStringContainsString('Mga item na may supplier na at kailangan nang i-order', $ceo);
    }

    public function test_toolbar_wraps_and_expand_all_has_a_fixed_width(): void
    {
        foreach ([true, false] as $ceo) {
            $html = $this->render($ceo);
            $this->assertMatchesRegularExpression('/#nav\s*\{[^}]*flex-wrap:wrap;[^}]*min-height:52px/', $html);
            $this->assertDoesNotMatchRegularExpression('/#nav\s*\{[^}]*[^-]height:52px/', $html);
            $this->assertStringContainsString('width:118px;text-align:center;white-space:nowrap', $html);
        }
    }

    public function test_new_table_has_the_narrow_screen_breakpoints(): void
    {
        $html = $this->render(true);
        $this->assertStringContainsString('@media (max-width: 1365px)', $html);
        $this->assertStringContainsString('@media (max-width: 1099px)', $html);
        foreach (['<col class="il-col-action"', '<th class="sortable il-col-action"', '<td class="il-col-action il-c-hide"'] as $m) {
            $this->assertStringContainsString($m, $html);
        }
        $this->assertMatchesRegularExpression('/\.il-col-action\s*\{\s*display:none\s*!important/', $html);
        $this->assertStringContainsString('.il-only-lt1366{display:block;}', $html);
        $this->assertStringContainsString('.il-only-lt1100{display:block;}', $html);
        $this->assertStringContainsString('.il-table tbody tr.il-row{display:grid', $html);
        $this->assertStringContainsString('class="il-m-label"', $html);
        $this->assertStringContainsString('tr.il-total', $html);
    }

    public function test_colspan_follows_the_columns_the_media_queries_leave_visible(): void
    {
        $html = $this->render(true);
        // Parehong query ng CSS (@media max-width 1439 / 1365).
        $this->assertStringContainsString("window.matchMedia('(max-width: 1439px)')", $html);
        $this->assertStringContainsString("window.matchMedia('(max-width: 1365px)')", $html);
        $this->assertStringContainsString("addEventListener('change'", $html);
        $this->assertMatchesRegularExpression('/ilColspan\(\)\s*\{[^}]*ilVw\.lt1440[^}]*ilVw\.lt1366/s', $html);
        $table = file_get_contents(resource_path('views/item/_table_new.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/(?<!:)colspan="\d+"/', $table);
        $this->assertStringContainsString(':colspan="ilColspan()"', $table);
    }

    public function test_chevron_button_fits_its_column(): void
    {
        $html = $this->render(true);
        $this->assertMatchesRegularExpression('/\.il-chev\s*\{[^}]*width:24px;\s*height:24px/', $html);
    }

    public function test_pct_helper_shows_the_unicode_minus_with_the_down_arrow(): void
    {
        $html = $this->render(true);
        // "▼ −3.2%" / "▲ 15.8%": may space pagkatapos ng arrow, U+2212 para sa negatibo.
        $this->assertStringContainsString("(Number(v) >= 0 ? '▲ ' : '▼ −')", $html);
    }

    public function test_new_layout_font_sizes_are_never_below_11px(): void
    {
        $dir = base_path('resources/views/item/');
        $index = file_get_contents($dir . 'index.blade.php');
        $start = strpos($index, '/* ── Bagong layout (005)');
        $this->assertNotFalse($start);
        $css = substr($index, $start, strpos($index, '</style>', $start) - $start);
        $css = preg_replace('/\[style\*="[^"]*"\]/', '', $css); // attribute selectors lang, hindi totoong size
        $sources = ['il css' => $css];
        foreach (['_table_new', '_il_expand', '_il_lifecycle'] as $p) {
            $sources[$p] = file_get_contents($dir . $p . '.blade.php');
        }
        foreach ($sources as $name => $src) {
            preg_match_all('/font-size:\s*(\d+(?:\.\d+)?)px/', $src, $m);
            foreach ($m[1] as $px) {
                $this->assertGreaterThanOrEqual(11, (float) $px, "$name has font-size {$px}px");
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\sx-html\s*=/', implode('', $sources));
    }
}
