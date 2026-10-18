<?php

namespace Tests\Feature\Chatblast;

/**
 * Ang Chatblast bilang sariling app: iisang layout at nav para sa Dashboard, Messages, Sequences at Page settings, ang
 * /page-settings na lumipat sa /chatblast/page-settings, at ang iisang Chatblast link sa main nav ng Likha.
 */
class ChatblastAppTest extends ChatblastTestCase
{
    public static function oldLinks(): array
    {
        return [
            'page'         => ['/page-settings?a=1&b=two', '/chatblast/page-settings?a=1&b=two'],
            'walang query' => ['/page-settings', '/chatblast/page-settings'],
            'sub-page'     => ['/page-settings/customers?page=Page%20Uno', '/chatblast/page-settings/customers?page=Page%20Uno'],
        ];
    }

    /** @dataProvider oldLinks */
    public function test_S_056_2_an_old_page_settings_link_moves_permanently_with_its_query(string $old, string $new): void
    {
        $this->actingAs($this->user());

        $this->get($old)->assertStatus(301)->assertRedirect($new);
    }
}
