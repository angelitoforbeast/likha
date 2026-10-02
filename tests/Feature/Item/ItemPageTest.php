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
        // Link papunta sa kabilang view (English, 006).
        $this->assertStringContainsString('🗂 Old view', $new);
        $this->assertStringNotContainsString('✨ New view', $new);
        $this->assertStringContainsString('✨ New view', $old);
        $this->assertStringNotContainsString('🗂 Old view', $old);
        $this->assertStringContainsString('title="Open the original table (all columns, scrolls sideways)"', $new);
        $this->assertStringContainsString('title="Back to the simple view"', $old);
        // Pinapanatili ng load() ang ?layout=old sa URL.
        $this->assertStringContainsString("qsObj.layout = 'old'", $new);
        $this->assertStringContainsString('layoutOld: false,', $new);
        $this->assertStringContainsString('layoutOld: true,', $old);
    }

    // ── 006 T1: tabs, English chrome ──────────────────────────────────────────

    /** Mga label ng <th><span>…</span> sa loob ng isang table (hanggang </thead>), sa pagkakasunod, kasama ang title. */
    private function headers(string $html, string $tableId): array
    {
        $start = strpos($html, 'id="' . $tableId . '"');
        $this->assertNotFalse($start, "table {$tableId} missing");
        $head = substr($html, $start, strpos($html, '</thead>', $start) - $start);
        preg_match_all('/<th\b([^>]*)>\s*<span>([^<]+)<\/span>/', $head, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $h) {
            $this->assertMatchesRegularExpression('/\stitle="[^"]+"/', $h[1], "header {$h[2]} has no title");
            $out[] = html_entity_decode($h[2]);
        }
        return $out;
    }

    public function test_default_render_has_both_tabs_to_order_first_and_both_header_sets_with_titles(): void
    {
        foreach ([$this->render(true), $this->render(false)] as $html) {
            // Tabs: totoong button, role=tab, aria-selected, isang tooltip bawat isa.
            $this->assertSame(2, substr_count($html, 'role="tab"'));
            $this->assertMatchesRegularExpression('/<button type="button" role="tab"[^>]*:aria-selected="ilTab === \'order\'[^>]*title="[^"]+"[^>]*>To order<\/button>/', $html);
            $this->assertMatchesRegularExpression('/<button type="button" role="tab"[^>]*:aria-selected="ilTab === \'sales\'[^>]*title="[^"]+"[^>]*>Sales &amp; Profit<\/button>/', $html);
            $this->assertLessThan(strpos($html, '>Sales &amp; Profit</button>'), strpos($html, '>To order</button>'));
            // Default = To order; ?tab=sales lang (eksakto) ang pumipili ng Sales.
            $this->assertStringContainsString("ilTab: (new URLSearchParams(window.location.search).get('tab') === 'sales') ? 'sales' : 'order',", $html);
            // Click: replaceState gaya ng setWorklist; load() pinapanatili ang tab.
            $this->assertStringContainsString("if (this.ilTab === 'sales') qs.set('tab', 'sales'); else qs.delete('tab');", $html);
            $this->assertStringContainsString("if (this.ilTab === 'sales') qsObj.tab = 'sales';", $html);
            // Isang table lang ang nasa DOM: ang napiling tab.
            $this->assertStringContainsString('<template x-if="ilTab === \'order\'">', $html);
            $this->assertStringContainsString('<template x-if="ilTab === \'sales\'">', $html);

            $this->assertSame(['Item', 'Next step', 'Qty to order', 'Days left', 'Profit (7 days)', 'Trend'],
                $this->headers($html, 'il-table-order'));
            $this->assertSame(['Item', 'Orders today', 'Profit today', 'Profit %', 'Ad spend', 'Cost per order'],
                $this->headers($html, 'il-table-sales'));
        }
    }

    public function test_chips_are_in_english(): void
    {
        $ceo = $this->render(true);
        foreach (["{ key:'lahat',      label:'All' }", "{ key:'hanapan',    label:'Need a supplier' }",
                  "{ key:'may_quote',  label:'Has a quote, not ordered' }", "{ key:'i_order',    label:'Ready to order' }",
                  "{ key:'naka_order', label:'Ordered, waiting' }",
                  "'Items that have a supplier and need to be ordered now'"] as $s) {
            $this->assertStringContainsString($s, $ceo);
        }
        foreach (["label:'Lahat'", 'Hanapan ng supplier', 'May quote, hindi pa na-order',
                  'Handa nang i-order (may supplier)', 'Naka-order, hinihintay'] as $s) {
            $this->assertStringNotContainsString($s, $ceo);
        }
    }

    public function test_default_render_chrome_is_english(): void
    {
        $english = [
            '<option value="">All</option>', '<option value="__none">No category</option>',
            'No data for the selected dates.', 'Loading…',
            "'Close all items and campaigns'", '✓ Copied all', 'Close (Esc)', 'No edit history yet.',
            'Could not load the worklist (', 'Could not load the stock (',
        ];
        $taglish = [
            'Lumang view', 'Bagong view', 'Hanapan ng supplier', 'Naka-order, hinihintay',
            'Walang item sa listahang ito', 'Walang category', 'Kopyahin LAHAT', 'Copied lahat',
            'Walang edit history pa', 'Isara (Esc)', 'Hindi ma-load', 'Isara lahat', 'Buksan ang pages',
            'anong aksyon ginawa', "placeholder=\"hal. ", 'blanko = panatilihin', 'Iwang <b>blanko',
            'Pwede ka mag-set', 'start ng period', "Iba't ibang", 'if walang promo', 'Set sa /ads_manager',
            'Walang item sa category na ito', 'Pumili ng supplier', 'Tanggalin si ', 'Maglagay ng pangalan',
            'Walang rows na visible', 'view ng /owner/private', 'o iwang blanko',
        ];
        foreach ([$this->render(true), $this->render(false)] as $html) {
            foreach ($english as $s) {
                $this->assertStringContainsString($s, $html);
            }
            foreach ($taglish as $s) {
                $this->assertStringNotContainsString($s, $html);
            }
        }
        $ceo = $this->render(true);
        $this->assertStringContainsString("'No items in this list.'", $ceo);
        $this->assertStringContainsString("'No items in this category.'", $ceo);
    }

    public function test_old_table_partial_is_byte_identical_to_the_base_commit(): void
    {
        // sha1 ng resources/views/item/_table_old.blade.php sa d9606c8 (hindi ginagalaw ng 006).
        // LF muna ang line endings para pareho ang hash kahit CRLF ang checkout (Windows).
        $src = str_replace("\r\n", "\n", file_get_contents(resource_path('views/item/_table_old.blade.php')));
        $this->assertSame('d3b53d274bb451ee6173df0606d951563c84b299', sha1($src));
    }

    public function test_item_views_have_no_x_html(): void
    {
        foreach (glob(resource_path('views/item/*.blade.php')) as $f) {
            $this->assertDoesNotMatchRegularExpression('/\sx-html\s*=/', file_get_contents($f), basename($f));
        }
    }

    public function test_ceo_only_editors_moved_to_the_details_are_absent_for_marketing(): void
    {
        $ceoOnly = ['openStockEdit(G.item_name', 'openQuote(G.item_name', 'deleteQuote(G.item_name', '@click.stop="saveQuote()"',
                    'wala pang supplier', 'blank = default ng lifecycle', 'saveSupplySettings(G.item_name',
                    'x-for="(s, si) in suppliersFor(G.item_name)"', "'kabuuan: '"];
        $ceo = $this->render(true);
        $mkt = $this->render(false);
        $expand = file_get_contents(resource_path('views/item/_il_expand.blade.php'));
        foreach ($ceoOnly as $s) {
            $this->assertStringContainsString($s, $expand, "{$s} is not in the details");
            $this->assertStringContainsString($s, $ceo);
            $this->assertStringNotContainsString($s, $mkt);
        }
        // Marketing: lead line plain text lang (walang ✎ button).
        $this->assertStringContainsString('ilLeadLine(G.item_name)', $mkt);
        // Wala nang nag-iinclude ng 005 table.
        $this->assertStringNotContainsString("item._table_new", file_get_contents(resource_path('views/item/index.blade.php')));
    }

    // ── 006 T2: To order rows ─────────────────────────────────────────────────

    public function test_to_order_cells_show_one_english_state_each_behind_their_column_grant(): void
    {
        $cells = [
            // Next step + reason (spec §4.1, §4.2)
            "x-for=\"N in [ilNext(G.item_name)]\"", "'il-tone-' + N.tone", 'ilReason(G.item_name)',
            "'🟠 Count the stock first'", "'⚪ Barely selling'", "'🟢 OK'", "'🔴 Find a supplier'",
            "'🔴 Order from ' + sup + ' now'", "'🔴 Order now'", "'🟡 Order from ' + sup + ' by '", "'🟡 Order by '",
            "'count is below zero'", "'only the orders waiting'", "' waiting + '", "' days of sales'", "' arriving'", "'enough stock'",
            "['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']",
            // Qty (§4.3), Days left (§4.4)
            'ilQty(G.item_name)', "' pcs'", 'ilDaysLeft(G.item_name)', "'Short '", "'1 year+'", "'<1 day'",
            // Profit (7 days) (§4.5): pinagsamang base item, may tint
            "ilCfTint('proj_pct_7d', baseProfitPct7(G.item_name))", 'ilPesoWhole(baseProfit7(G.item_name))', 'ilProfit7Tip(G.item_name)',
            "'All variants: '",
            // Trend
            "new:'🆕 New'", "scaling:'📈 Growing'", "consistent:'✅ Steady'", "active:'🔄 Active'",
            "declining:'📉 Slowing'", "phasing_out:'🚫 Stopping'", "dormant:'💤 Stopped'", 'losing money',
            'ilTrendText(G.item_name)', 'ilTrendTip(G.item_name)',
            // Column grants = data gate (005 mapping)
            "x-show=\"ilIdOn('order_qty')\"", "x-show=\"ilIdOn('doi')\"", "x-show=\"ilIdOn('proj_pct_7d')\"",
            "x-show=\"ilIdOn('lifecycle')\"", ':colspan="ilOrderColspan()"',
        ];
        foreach ([$this->render(true), $this->render(false)] as $html) {
            foreach ($cells as $s) {
                $this->assertStringContainsString($s, $html);
            }
        }
        $table = file_get_contents(resource_path('views/item/_table_order.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/(?<!:)colspan="\d+"/', $table);
        $this->assertStringContainsString("@include('item._il_lifecycle')", $table);
    }

    public function test_profit_tint_uses_the_owner_rule_colour_lightly_or_the_15_and_0_default(): void
    {
        $html = $this->render(true);
        $this->assertMatchesRegularExpression('/ilCfTint\(colId, v\)\s*\{.*?\n      \},/s', $html);
        preg_match('/ilCfTint\(colId, v\)\s*\{.*?\n      \},/s', $html, $m);
        foreach (['(window.__COL_FORMAT__ || {})[colId]', 'this._evalRules(rules, v, null, null)',
                  '/^#[0-9a-f]{3,8}$/i', '/^[a-z]+$/i', 'n >= 15', 'n >= 0',
                  "'background:color-mix(in srgb, ' + colour + ' 22%, white);color:#111827;'"] as $s) {
            $this->assertStringContainsString($s, $m[0]);
        }
        // Sibling ng baseProfitPct7 sa parehong cache.
        $this->assertStringContainsString('baseProfit7(name){', $html);
    }

    public function test_marketing_to_order_has_no_cost_line_supplier_names_or_find_a_supplier(): void
    {
        $ceo = $this->render(true);
        $mkt = $this->render(false);
        $this->assertStringContainsString('ilQtyCost(G.item_name)', $ceo);
        $this->assertStringNotContainsString('ilQtyCost(G.item_name)', $mkt);
        foreach ([$ceo, $mkt] as $html) {
            // JS gate din: walang pangalan / cost / Find a supplier kapag hindi CEO view.
            $this->assertMatchesRegularExpression('/ilSupplier\(name\)\s*\{\s*if \(!this\.effectiveIsCeo\) return \'\';/', $html);
            $this->assertMatchesRegularExpression('/ilQtyCost\(name\)\s*\{\s*if \(!this\.effectiveIsCeo\) return \'\';/', $html);
            $this->assertStringContainsString("if (this.effectiveIsCeo && !this.suppliersFor(name).length && !this.quotesFor(name).length) return st('find'", $html);
            // Supplier = pinakamurang quote na may presyo.
            $this->assertStringContainsString('Number(q.price) < Number(best.price)', $html);
            $this->assertStringContainsString("' (min '", $html);
        }
    }

    public function test_replaced_005_row_texts_are_gone_from_the_new_view(): void
    {
        foreach ([$this->render(true), $this->render(false)] as $html) {
            // Wala na kahit saan (tinanggal ang ilPill / ilQtyText / ilLifecycleText / ilLcStyle).
            foreach (['Hanap muna ng supplier', 'Umorder ', 'Bilangin muna ang stock', 'ilPill(', 'ilQtyText(', 'ilLcStyle('] as $s) {
                $this->assertStringNotContainsString($s, $html);
            }
            // Wala sa markup (puwedeng nasa JS pa ng lumang view / T3 / T5 helpers).
            $markup = substr($html, 0, strrpos($html, '<script>'));
            foreach (['Kulang: ', 'Sapat: ', '🆕 Bago', 'Lumalaki', 'Bumababa', 'Itinitigil', 'Tulog', 'Ikaw ang nagtakda'] as $s) {
                $this->assertStringNotContainsString($s, $markup);
            }
        }
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

    public function test_expanded_block_and_new_tables_have_no_cell_fills(): void
    {
        foreach (['item/_il_expand.blade.php', 'item/_table_order.blade.php', 'item/_table_sales.blade.php'] as $f) {
            $src = file_get_contents(resource_path('views/' . $f));
            foreach (['pbStyle(', 'pbStyleN(', 'cellFormatStyle(', 'background:#fef2f2'] as $fill) {
                $this->assertStringNotContainsString($fill, $src, "{$f} has {$fill}");
            }
        }
    }

    public function test_new_layout_review_minors_are_in_the_markup(): void
    {
        $html = $this->render(true);
        $expand = file_get_contents(resource_path('views/item/_il_expand.blade.php'));
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
        // 5: walang "0.0" kapag null ang units_per_day (narrow duplicate sa expanded block)
        $this->assertStringContainsString('units_per_day != null) ? Number(', $expand);
    }

    public function test_default_item_order_is_by_next_step_rank_in_the_new_layout_and_by_hold_in_the_old(): void
    {
        $html = $this->render(true);
        $this->assertStringContainsString('} else if (this.layoutOld) {', $html);
        $this->assertStringContainsString('out.sort((a, b) => b.hold - a.hold);', $html);
        $rank = strpos($html, 'const rank = new Map(out.map(g => [g, this.ilNext(g.item_name).rank]));');
        $this->assertNotFalse($rank);
        $this->assertLessThan($rank, strpos($html, '} else if (this.layoutOld) {'));
        $this->assertStringContainsString('out.sort((a, b) => (rank.get(a) - rank.get(b)) || (b.hold - a.hold));', $html);
        // Wala nang 005 urgency helpers.
        foreach (['ilUrgency(', 'ilAabot('] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    public function test_every_to_order_header_sorts_and_switching_tab_resets_the_sort(): void
    {
        $html = $this->render(true);
        $start = strpos($html, 'id="il-table-order"');
        $head = substr($html, $start, strpos($html, '</thead>', $start) - $start);
        foreach (['item_name', 'il_next', 'order_qty', 'doi', 'il_profit7', 'lifecycle'] as $key) {
            $this->assertStringContainsString("tabindex=\"0\" @click=\"sb('{$key}')\" @keydown.enter=\"sb('{$key}')\"", $head);
            $this->assertStringContainsString("x-text=\"arr('{$key}')\"", $head);
        }
        $this->assertStringContainsString("case 'il_next':               return this.ilNext(grp.item_name).rank;", $html);
        $this->assertStringContainsString("case 'il_profit7':            return this.baseProfitPct7(grp.item_name);", $html);
        // setTab: bumalik sa default sort (walang nakatagong sort na naiiwan).
        $this->assertMatchesRegularExpression("/setTab\(tab\)\{[^}]*this\.sortCol = ''; this\.sortDir = 'desc';/s", $html);
    }

    public function test_to_order_total_counts_the_red_rows_and_shows_pesos_to_the_ceo_only(): void
    {
        $ceo = $this->render(true);
        $mkt = $this->render(false);
        foreach ([$ceo, $mkt] as $html) {
            foreach (['x-for="T in [ilOrderTotal()]"', "'To order now: '", "' items · '", "' item · '", "' pcs'",
                      "'To order now: nothing urgent'", "if (this.ilNext(G.item_name).tone !== 'red') continue;",
                      'class="il-total"'] as $s) {
                $this->assertStringContainsString($s, $html);
            }
            $this->assertSame(1, substr_count($html, 'ilOrderTotal()]'));
            $this->assertMatchesRegularExpression('/ilQtyCostAmount\(name\)\s*\{\s*if \(!this\.effectiveIsCeo\) return null;/', $html);
        }
        $this->assertStringContainsString("' · ≈ ₱' + num(Math.round(T.peso))", $ceo);
        $this->assertStringNotContainsString("' · ≈ ₱' + num(Math.round(T.peso))", $mkt);
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

    public function test_old_total_is_unchanged_and_only_in_the_old_view(): void
    {
        foreach ([true, false] as $ceo) {
            $this->assertStringNotContainsString('<td>TOTAL</td>', $this->render($ceo));

            $old = $this->render($ceo, true);
            $this->assertStringContainsString('<td>TOTAL</td>', $old);
            $this->assertStringContainsString('tot() { return this.aggOf(this.filteredRows()); }', $old);
            $this->assertStringNotContainsString('class="il-total"', $old);
        }
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
        $this->assertStringContainsString('.il-only-lt1366{display:block;}', $html);
        $this->assertStringContainsString('.il-only-lt1100{display:block;}', $html);
        $this->assertStringContainsString('.il-table tbody tr.il-row{display:grid', $html);
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

    public function test_kita_ngayon_uses_whole_pesos_from_1000_and_only_there(): void
    {
        $html = $this->render(true);
        // Helper: ≥1,000 buo (walang .00), mas mababa = dalawang decimal; "−₱" style ng ilMoney.
        $this->assertMatchesRegularExpression('/ilMoneyKita\(v\)\s*\{[^}]*>=\s*1000[^}]*maximumFractionDigits:0/s', $html);
        $expand = file_get_contents(resource_path('views/item/_il_expand.blade.php'));
        $this->assertStringContainsString('ilMoneyKita(G.agg.projected_profit_last_day)', $expand);
        $this->assertStringNotContainsString('ilMoney(G.agg.projected_profit_last_day)', $expand);
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
        foreach (['_table_order', '_table_sales', '_il_expand', '_il_lifecycle'] as $p) {
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
