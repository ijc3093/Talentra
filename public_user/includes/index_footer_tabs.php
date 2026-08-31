<?php
declare(strict_types=1);

function index_footer_tabs(): array
{
  return [
    'about' => 'About',
    'guidance' => 'Guidance',
    'help' => 'Help',
    'policy' => 'Policy',
    'terms' => 'Terms',
    'locations' => 'Locations',
    'shop' => 'Shop',
    'popular' => 'Popular',
    'live' => 'Live',
  ];
}

function index_footer_tab_from_request(): string
{
  $tab = strtolower(trim((string)($_GET['tab'] ?? '')));
  if (array_key_exists($tab, index_footer_tabs())) {
    return $tab;
  }
  if (array_key_exists($tab, index_feature_articles())) {
    return $tab;
  }
  if (index_help_topic_for_tab($tab) !== null) {
    return $tab;
  }
  return '';
}

function index_footer_tab_href(string $tab, bool $addingAccount = false): string
{
  $query = ['tab' => $tab];
  if ($addingAccount) {
    $query['add_account'] = '1';
  }
  return 'index.php?' . http_build_query($query);
}

function index_footer_tab_h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function index_help_guides_for_article(array $article): array
{
  $guides = (array)($article['guides'] ?? []);
  if ($guides) {
    $out = [];
    foreach ($guides as $guide) {
      $guide = (array)$guide;
      if (trim((string)($guide['heading'] ?? '')) === '') {
        $guide['heading'] = (string)($guide['title'] ?? '');
      }
      if (empty($guide['steps'])) {
        $text = trim((string)($guide['text'] ?? ''));
        if ($text !== '' && trim((string)($guide['note'] ?? '')) === '') {
          $guide['note'] = $text;
        }
      }
      $out[] = $guide;
    }
    return $out;
  }
  $paras = [];
  foreach ((array)($article['paras'] ?? []) as $para) {
    $para = trim((string)$para);
    if ($para !== '') {
      $paras[] = $para;
    }
  }
  if (!$paras) {
    return [];
  }
  $title = (string)($article['title'] ?? '');
  return [[
    'title' => $title,
    'heading' => $title,
    'steps' => $paras,
  ]];
}

function index_help_linked_text(string $text, array $links, bool $addingAccount): string
{
  $html = index_footer_tab_h($text);
  foreach ($links as $link) {
    $label = index_footer_tab_h((string)($link['text'] ?? ''));
    if ($label === '' || !str_contains($html, $label)) {
      continue;
    }
    $tab = trim((string)($link['tab'] ?? ''));
    $hash = trim((string)($link['hash'] ?? ''));
    if ($tab !== '') {
      $href = index_footer_tab_href($tab, $addingAccount);
      $anchor = '<a class="hc-acc-link js-index-tab" href="' . index_footer_tab_h($href) . '" data-index-tab="' . index_footer_tab_h($tab) . '">' . $label . '</a>';
    } elseif ($hash !== '') {
      $anchor = '<a class="hc-acc-link js-hc-acc-jump" href="#' . index_footer_tab_h($hash) . '">' . $label . '</a>';
    } else {
      continue;
    }
    $html = str_replace($label, $anchor, $html);
  }
  return $html;
}

function index_help_copy_icon(): string
{
  return '<svg class="hc-acc-ico" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M10 13a5 5 0 0 0 7.07 0l1.41-1.41a5 5 0 0 0-7.07-7.07L10 6"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M14 11a5 5 0 0 0-7.07 0L5.52 12.41a5 5 0 0 0 7.07 7.07L14 18"/></svg>';
}

function index_help_open_icon(): string
{
  return '<svg class="hc-acc-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="4.5" y="7" width="12.5" height="12.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M10 14l9-9M13 5h6v6"/></svg>';
}

function index_help_ui_icon(string $name): string
{
  $paths = [
    'user' => '<circle cx="12" cy="8.2" r="3"/><path d="M6 18.5c1.1-2.8 3.2-4.2 6-4.2s4.9 1.4 6 4.2"/>',
    'tag' => '<rect x="5" y="5" width="14" height="14" rx="3"/><circle cx="12" cy="10.2" r="2"/><path d="M8.2 16.8c.7-1.6 2-2.4 3.8-2.4s3.1.8 3.8 2.4"/>',
    'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 5.2v1.6M12 17.2v1.6M5.2 12h1.6M17.2 12h1.6M7.2 7.2l1.1 1.1M15.7 15.7l1.1 1.1M16.8 7.2l-1.1 1.1M8.3 15.7l-1.1 1.1"/>',
    'lock' => '<rect x="7" y="11" width="10" height="8" rx="1.6"/><path d="M9 11V8.6a3 3 0 0 1 6 0V11"/>',
    'plus' => '<rect x="5" y="5" width="14" height="14" rx="3"/><path d="M12 8.5v7M8.5 12h7"/>',
    'plane' => '<path d="M4 12l16-7-6 16-2.2-6.2L4 12z"/>',
    'search' => '<circle cx="11" cy="11" r="5"/><path d="M15.5 15.5L19 19"/>',
    'play' => '<rect x="5" y="5" width="14" height="14" rx="4"/><path d="M10 8.8l6 3.2-6 3.2z"/>',
    'live' => '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="6.5"/>',
    'story' => '<rect x="7" y="4.5" width="10" height="15" rx="3"/>',
    'cart' => '<path d="M5 7h2l1.2 9h9.2l1.4-7H8"/><circle cx="10" cy="19" r="1.2"/><circle cx="16.5" cy="19" r="1.2"/>',
    'home' => '<path d="M4.5 12L12 5.5 19.5 12"/><path d="M7 11v8h10v-8"/>',
    'archive' => '<rect x="4.5" y="5" width="15" height="4" rx="1"/><path d="M6 9v10h12V9M10 12h4"/>',
    'menu' => '<circle cx="12" cy="6.5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="17.5" r="1.2"/>',
    'device' => '<rect x="7" y="4" width="10" height="16" rx="2"/><path d="M10 17.5h4"/>',
  ];
  $inner = $paths[$name] ?? $paths['user'];
  return '<svg class="hc-ui-ico" viewBox="0 0 24 24" aria-hidden="true">' . $inner . '</svg>';
}

function index_help_ui_chip(string $icon, string $escapedLabel): string
{
  return '<span class="hc-ui-chip">' . index_help_ui_icon($icon) . '<b>' . $escapedLabel . '</b></span>';
}

function index_help_step_html(string $text): string
{
  $html = index_footer_tab_h($text);
  $marks = [
    'profile picture' => 'user',
    'About Me' => 'user',
    'Live Studio' => 'live',
    'Manage devices' => 'device',
    'post menu' => 'menu',
    'Circle' => 'home',
    'Circle' => 'home',
    'Privacy' => 'lock',
    'Compose' => 'plus',
    'Messages' => 'plane',
    'Archive' => 'archive',
    'Discover' => 'search',
    'Popular' => 'search',
    'Clips' => 'play',
    'Clips' => 'play',
    'Moments' => 'story',
    'Moments' => 'story',
    'Tags' => 'tag',
    'Gear' => 'gear',
    'Shop' => 'cart',
    'Home' => 'home',
    'Profile' => 'user',
    'Live' => 'live',
  ];
  foreach ($marks as $word => $icon) {
    $esc = index_footer_tab_h($word);
    $pattern = '/(?<![\p{L}])' . preg_quote($esc, '/') . '(?![\p{L}])/u';
    $chip = index_help_ui_chip($icon, $esc);
    $html = preg_replace($pattern, $chip, $html, 1) ?? $html;
  }
  return $html;
}

function index_footer_tab_groups(): array
{
  return [
    [
      'label' => 'Using Talsora',
      'items' => ['about', 'guidance', 'help', 'shop', 'popular', 'live'],
    ],
  ];
}

function index_feature_nav_topics(): array
{
  return [
    [
      'id' => 'profile',
      'label' => 'Your Profile',
      'icon' => 'user',
      'items' => [
        ['tab' => 'profile-tagged', 'label' => 'Posts with your name on them'],
        ['tab' => 'profile-edit', 'label' => 'Change your profile'],
        ['tab' => 'profile-visibility', 'label' => 'Who can open your profile'],
      ],
    ],
    [
      'id' => 'sharing',
      'label' => 'Posting photos and video',
      'icon' => 'plus',
      'items' => [
        ['tab' => 'share-post', 'label' => 'Publish a post'],
        ['tab' => 'share-effects', 'label' => 'Looks and filters'],
        ['tab' => 'share-edit-delete', 'label' => 'Change or remove a post'],
        ['tab' => 'share-other-networks', 'label' => 'Send a Talsora link'],
        ['tab' => 'share-tags', 'label' => 'Names on a post'],
      ],
    ],
    [
      'id' => 'exploring',
      'label' => 'Finding photos and video',
      'icon' => 'search',
      'items' => [
        ['tab' => 'explore-search', 'label' => 'Search'],
        ['tab' => 'explore-activity', 'label' => 'Hashtags and places'],
        ['tab' => 'explore-feed', 'label' => 'How Home is built'],
        ['tab' => 'explore-notes', 'label' => 'Notes and extra context'],
      ],
    ],
    [
      'id' => 'messaging',
      'label' => 'Chat',
      'icon' => 'plane',
      'items' => [
        ['tab' => 'msg-manage', 'label' => 'Send and read chats'],
        ['tab' => 'msg-groups', 'label' => 'Group chats'],
        ['tab' => 'msg-calls', 'label' => 'Audio and video calls'],
        ['tab' => 'msg-requests', 'label' => 'Chat requests'],
        ['tab' => 'msg-channels', 'label' => 'Public channels'],
      ],
    ],
    [
      'id' => 'reels',
      'label' => 'Clips',
      'icon' => 'play',
      'items' => [
        ['tab' => 'reels-create', 'label' => 'Make a clip'],
        ['tab' => 'reels-manage', 'label' => 'Your clips'],
        ['tab' => 'reels-share', 'label' => 'Send a clip link'],
        ['tab' => 'reels-discover', 'label' => 'Watch clips'],
        ['tab' => 'reels-accounts', 'label' => 'Publisher and seller clips'],
      ],
    ],
    [
      'id' => 'edits',
      'label' => 'Trim',
      'icon' => 'split',
      'items' => [
        ['tab' => 'edits-trim', 'label' => 'Length, crop, and cover'],
        ['tab' => 'edits-save', 'label' => 'Leave a draft'],
      ],
    ],
    [
      'id' => 'stories',
      'label' => 'Moments',
      'icon' => 'story',
      'items' => [
        ['tab' => 'stories-add', 'label' => 'Add a moment'],
        ['tab' => 'stories-watch', 'label' => 'Watch moments'],
        ['tab' => 'stories-highlights', 'label' => 'Keep a moment on your profile'],
        ['tab' => 'stories-privacy', 'label' => 'Who can watch and reply'],
      ],
    ],
    [
      'id' => 'live-help',
      'label' => 'Live',
      'icon' => 'live',
      'items' => [
        ['tab' => 'live-watch', 'label' => 'Watch Public Live'],
        ['tab' => 'live-host', 'label' => 'Host from Live Studio'],
        ['tab' => 'live-during', 'label' => 'During a Live'],
      ],
    ],
    [
      'id' => 'shop-feat',
      'label' => 'Shop',
      'icon' => 'cart',
      'items' => [
        ['tab' => 'shop', 'label' => 'Shop'],
        ['tab' => 'purchase-protection', 'label' => 'Shop purchase protection'],
      ],
    ],
    [
      'id' => 'pay-feat',
      'label' => 'Payments',
      'icon' => 'bill',
      'items' => [
        ['tab' => 'payments-terms', 'label' => 'Checkout and payouts'],
      ],
    ],
    [
      'id' => 'publisher',
      'label' => 'Publisher',
      'icon' => 'mega',
      'items' => [
        ['tab' => 'pub-create', 'label' => 'Create a publisher page'],
        ['tab' => 'pub-approval', 'label' => 'Publisher name approval'],
        ['tab' => 'pub-post', 'label' => 'Post, reels, and Live as a publisher'],
        ['tab' => 'pub-discover', 'label' => 'Be found on Discover'],
      ],
    ],
    [
      'id' => 'seller',
      'label' => 'Seller',
      'icon' => 'chart',
      'items' => [
        ['tab' => 'seller-create', 'label' => 'Create a seller account'],
        ['tab' => 'seller-list', 'label' => 'List products in Shop'],
        ['tab' => 'seller-orders', 'label' => 'Orders, messages, and payouts'],
        ['tab' => 'seller-rules', 'label' => 'Honest listings and Shop rules'],
      ],
    ],
  ];
}

function index_help_nav_sections(): array
{
  return [
    [
      'label' => 'Talsora Features',
      'topics' => index_feature_nav_topics(),
    ],
    [
      'label' => 'Login, Recovery and Security',
      'topics' => [
        [
          'id' => 'account-recovery',
          'label' => 'Account Recovery and Login',
          'icon' => 'key',
          'items' => [
            ['tab' => 'login-reset', 'label' => 'Reset your password'],
            ['tab' => 'login-cant', 'label' => 'Can\'t log in'],
            ['tab' => 'login-hacked', 'label' => 'If you think your account was taken'],
            ['tab' => 'login-devices', 'label' => 'Devices and extra protection'],
          ],
        ],
        [
          'id' => 'abuse-spam-nav',
          'label' => 'Abuse, Spam and Scams',
          'icon' => 'warn',
          'items' => [
            ['tab' => 'abuse-spam', 'label' => 'Report abuse, spam, or a scam'],
          ],
        ],
        [
          'id' => 'secure-account-nav',
          'label' => 'Secure your Talsora account',
          'icon' => 'phone',
          'items' => [
            ['tab' => 'secure-account', 'label' => 'Passwords, devices, and codes'],
          ],
        ],
      ],
    ],
    [
      'label' => 'Manage Your Account',
      'topics' => [
        [
          'id' => 'signup',
          'label' => 'Signing Up and Getting Started',
          'icon' => 'user',
          'items' => [
            ['tab' => 'signup-start', 'label' => 'Create an account and open Home'],
            ['tab' => 'signup-types', 'label' => 'Pick an account type'],
          ],
        ],
        [
          'id' => 'account-settings',
          'label' => 'Adjust Your Account Settings',
          'icon' => 'gear',
          'items' => [
            ['tab' => 'gear-settings', 'label' => 'Gear, profile, and sign-in'],
            ['tab' => 'account-download', 'label' => 'Download your information'],
            ['tab' => 'account-deactivate', 'label' => 'Deactivate or delete'],
          ],
        ],
        [
          'id' => 'notif',
          'label' => 'Notification Settings',
          'icon' => 'bell',
          'items' => [
            ['tab' => 'notif-settings', 'label' => 'What can notify you'],
          ],
        ],
        [
          'id' => 'adding-accounts',
          'label' => 'Adding Accounts',
          'icon' => 'users',
          'items' => [
            ['tab' => 'add-accounts', 'label' => 'Add or switch an account'],
          ],
        ],
        [
          'id' => 'verified',
          'label' => 'Verified Badges',
          'icon' => 'badge',
          'items' => [
            ['tab' => 'verified-badges', 'label' => 'Approval vs a checkmark'],
          ],
        ],
        [
          'id' => 'a11y',
          'label' => 'Accessibility',
          'icon' => 'access',
          'items' => [
            ['tab' => 'accessibility', 'label' => 'Text size, contrast, and Help'],
          ],
        ],
        [
          'id' => 'ads',
          'label' => 'About Talsora ads',
          'icon' => 'mega',
          'items' => [
            ['tab' => 'about-ads', 'label' => 'Promotions and shop listings'],
          ],
        ],
      ],
    ],
    [
      'label' => 'Staying Safe',
      'topics' => [
        [
          'id' => 'share-safe',
          'label' => 'Sharing Photos Safely',
          'icon' => 'phone',
          'items' => [
            ['tab' => 'share-safely', 'label' => 'What to post and what to skip'],
          ],
        ],
        [
          'id' => 'safety-tips',
          'label' => 'Safety Tips',
          'icon' => 'warn',
          'items' => [
            ['tab' => 'safety-tips', 'label' => 'Strangers, codes, and block'],
          ],
        ],
        [
          'id' => 'parents',
          'label' => 'Tips for Parents',
          'icon' => 'users',
          'items' => [
            ['tab' => 'tips-parents', 'label' => 'Age 21+ and family talk'],
          ],
        ],
        [
          'id' => 'authentic',
          'label' => 'Being yourself on Talsora',
          'icon' => 'star',
          'items' => [
            ['tab' => 'authentic-self', 'label' => 'Name, photo, and pace'],
          ],
        ],
        [
          'id' => 'abuse-safe',
          'label' => 'Abuse, Spam and Scams',
          'icon' => 'warn',
          'items' => [
            ['tab' => 'abuse-spam', 'label' => 'Report abuse, spam, or a scam'],
          ],
        ],
        [
          'id' => 'conflict',
          'label' => 'Ways to deal with conflict or abuse',
          'icon' => 'block',
          'items' => [
            ['tab' => 'deal-conflict', 'label' => 'Restrict, block, report, leave'],
          ],
        ],
        [
          'id' => 'self-injury',
          'label' => 'Self-Injury',
          'icon' => 'flag',
          'items' => [
            ['tab' => 'self-injury', 'label' => 'Crisis help and reporting'],
          ],
        ],
        [
          'id' => 'eating',
          'label' => 'About Eating Disorders',
          'icon' => 'book',
          'items' => [
            ['tab' => 'eating-disorders', 'label' => 'Resources and reporting'],
          ],
        ],
        [
          'id' => 'leo',
          'label' => 'Information for law enforcement',
          'icon' => 'shield',
          'items' => [
            ['tab' => 'law-enforcement', 'label' => 'Lawful process, not a public lookup'],
          ],
        ],
      ],
    ],
    [
      'label' => 'Privacy and Reporting',
      'topics' => [
        [
          'id' => 'privacy-manage',
          'label' => 'Managing Your Privacy Settings',
          'icon' => 'lock',
          'items' => [
            ['tab' => 'privacy-settings', 'label' => 'Gear → Privacy'],
            ['tab' => 'privacy-audience', 'label' => 'Who can see your content'],
            ['tab' => 'privacy-block', 'label' => 'Block an account'],
            ['tab' => 'privacy-restrict', 'label' => 'Restrict an account'],
          ],
        ],
        [
          'id' => 'report',
          'label' => 'Report content on Talsora',
          'icon' => 'warn',
          'items' => [
            ['tab' => 'report-content', 'label' => 'Posts, profiles, live, and chat'],
          ],
        ],
        [
          'id' => 'osa-au',
          'label' => 'Australia Online Safety Act',
          'icon' => 'phone',
          'items' => [
            ['tab' => 'osa-au', 'label' => 'Report in Talsora and Help'],
          ],
        ],
        [
          'id' => 'osa-uk',
          'label' => 'UK Online Safety Act — complaint',
          'icon' => 'phone',
          'items' => [
            ['tab' => 'osa-uk', 'label' => 'In-product report, then the regulator'],
          ],
        ],
        [
          'id' => 'jp-act',
          'label' => 'Japan information-distribution rules',
          'icon' => 'users',
          'items' => [
            ['tab' => 'jp-platform', 'label' => 'Flag content through Help'],
          ],
        ],
        [
          'id' => 'impersonation',
          'label' => 'Impersonation Accounts',
          'icon' => 'users',
          'items' => [
            ['tab' => 'impersonation', 'label' => 'Report a fake you or brand'],
          ],
        ],
      ],
    ],
    [
      'label' => 'Terms and Policies',
      'topics' => [
        [
          'id' => 'guidelines',
          'label' => 'Community Guidelines',
          'icon' => 'shield',
          'items' => [
            ['tab' => 'community-guidelines', 'label' => 'What is not allowed'],
          ],
        ],
        [
          'id' => 'privacy-pol',
          'label' => 'Privacy Policy',
          'icon' => 'chart',
          'items' => [
            ['tab' => 'policy', 'label' => 'Privacy Policy'],
          ],
        ],
        [
          'id' => 'terms-use',
          'label' => 'Terms of Use',
          'icon' => 'info',
          'items' => [
            ['tab' => 'terms', 'label' => 'Terms of Use'],
          ],
        ],
        [
          'id' => 'platform-pol',
          'label' => 'Platform Policy',
          'icon' => 'phone',
          'items' => [
            ['tab' => 'platform-policy', 'label' => 'Enforcement and automation'],
          ],
        ],
        [
          'id' => 'cookies',
          'label' => 'Cookies Policy',
          'icon' => 'cookie',
          'items' => [
            ['tab' => 'cookies-policy', 'label' => 'Sign-in and appearance cookies'],
          ],
        ],
        [
          'id' => 'transparency',
          'label' => 'Transparency Center',
          'icon' => 'search',
          'items' => [
            ['tab' => 'transparency', 'label' => 'Requests and enforcement, at a high level'],
          ],
        ],
        [
          'id' => 'pay-terms',
          'label' => 'Shop money rules',
          'icon' => 'bill',
          'items' => [
            ['tab' => 'payments-terms', 'label' => 'Checkout and payouts'],
          ],
        ],
        [
          'id' => 'purchase',
          'label' => 'Shop purchase protection',
          'icon' => 'cart',
          'items' => [
            ['tab' => 'purchase-protection', 'label' => 'Orders, sellers, and Help'],
          ],
        ],
        [
          'id' => 'locations-pol',
          'label' => 'Locations',
          'icon' => 'search',
          'items' => [
            ['tab' => 'locations', 'label' => 'Locations'],
          ],
        ],
      ],
    ],
  ];
}

function index_help_topic_tab(array $topic): string
{
  $id = trim((string)($topic['id'] ?? ''));
  return $id === '' ? '' : 'topic-' . $id;
}

function index_help_each_topic(): array
{
  $out = [];
  foreach (index_help_nav_sections() as $section) {
    foreach ((array)($section['topics'] ?? []) as $topic) {
      $topic['_section'] = (string)($section['label'] ?? '');
      $out[] = $topic;
    }
  }
  return $out;
}

function index_help_topic_for_tab(string $tab): ?array
{
  foreach (index_help_each_topic() as $topic) {
    if (index_help_topic_tab($topic) === $tab) {
      return $topic;
    }
  }
  return null;
}

function index_help_parent_topic(string $tab): ?array
{
  $direct = index_help_topic_for_tab($tab);
  if ($direct !== null) {
    return $direct;
  }
  foreach (index_help_each_topic() as $topic) {
    foreach ((array)($topic['items'] ?? []) as $item) {
      if (($item['tab'] ?? '') === $tab) {
        return $topic;
      }
    }
  }
  return null;
}

function index_help_topic_keys(): array
{
  $keys = [];
  foreach (index_help_each_topic() as $topic) {
    $id = index_help_topic_tab($topic);
    if ($id !== '') {
      $keys[] = $id;
    }
  }
  return $keys;
}

function index_help_render_helpful(string $id): void
{
  echo '<div class="hc-helpful" data-helpful-id="' . index_footer_tab_h($id) . '">';
  echo '<p>Was this helpful?</p>';
  echo '<button type="button" class="hc-helpful-btn" data-helpful="yes">Yes</button>';
  echo '<button type="button" class="hc-helpful-btn" data-helpful="no">No</button>';
  echo '<p class="hc-helpful-done" hidden>Thanks. That helps us improve Help Center.</p>';
  echo '</div>';
}

function index_help_render_related(?array $topic, bool $addingAccount, string $currentTab): void
{
  if ($topic === null) {
    return;
  }
  $section = (string)($topic['_section'] ?? '');
  $cards = [];
  foreach (index_help_each_topic() as $other) {
    if ((string)($other['id'] ?? '') === (string)($topic['id'] ?? '')) {
      continue;
    }
    if ($section !== '' && (string)($other['_section'] ?? '') !== $section) {
      continue;
    }
    $tab = index_help_topic_tab($other);
    if ($tab === '' || $tab === $currentTab) {
      continue;
    }
    $cards[] = $other;
    if (count($cards) >= 3) {
      break;
    }
  }
  if (!$cards) {
    return;
  }
  echo '<section class="hc-related">';
  echo '<h2>Related topics</h2>';
  echo '<div class="hc-related-grid">';
  foreach ($cards as $other) {
    $tab = index_help_topic_tab($other);
    $href = index_footer_tab_href($tab, $addingAccount);
    $first = (array)(($other['items'][0] ?? []));
    $blurb = (string)($first['label'] ?? 'Open this topic');
    echo '<a class="hc-related-card js-index-tab" href="' . index_footer_tab_h($href) . '" data-index-tab="' . index_footer_tab_h($tab) . '">';
    echo '<strong>' . index_footer_tab_h((string)($other['label'] ?? '')) . '</strong>';
    echo '<span>' . index_footer_tab_h($blurb) . '</span>';
    echo '</a>';
  }
  echo '</div></section>';
}

function index_help_render_topic_landing(array $topic, string $activeTab, bool $addingAccount): void
{
  $tab = index_help_topic_tab($topic);
  $show = $activeTab === $tab ? '' : ' hidden';
  $label = (string)($topic['label'] ?? '');
  $items = (array)($topic['items'] ?? []);
  $copy = [
    'profile' => [
      'lead' => 'Your profile is where people find your posts, tags, and settings. Open it from Home or the left.',
      'learn' => ['Add a bio or a site in About Me.', 'Change your username and password in Gear.', 'Update your profile picture on Profile.'],
      'pills' => [
        ['label' => 'How do I update my profile picture?', 'tab' => 'profile-edit'],
        ['label' => 'How do I add a bio?', 'tab' => 'profile-edit'],
        ['label' => 'How do I change my username?', 'tab' => 'gear-settings'],
      ],
    ],
    'publisher' => [
      'lead' => 'A publisher page is a public channel on Talsora: news, a brand, or a voice people follow from Discover and Circle.',
      'learn' => [
        'Create a publisher account and pick a public name.',
        'Wait for name approval when the registry requires it.',
        'Post, make reels, and go live so followers have something to watch.',
      ],
      'pills' => [
        ['label' => 'How do I create a publisher page?', 'tab' => 'pub-create'],
        ['label' => 'Why is my publisher name waiting?', 'tab' => 'pub-approval'],
        ['label' => 'How do people find my page?', 'tab' => 'pub-discover'],
      ],
    ],
    'seller' => [
      'lead' => 'Sellers use a commerce account to list products in Shop, take orders, and message buyers. Selling is not done from a personal or publisher-only page.',
      'learn' => [
        'Create a commerce (seller) account and request the brand if it is new.',
        'List products, keep inventory honest, and watch buyer messages.',
        'Honor orders you accept. Disputes start in Messages, then Help.',
      ],
      'pills' => [
        ['label' => 'How do I become a seller?', 'tab' => 'seller-create'],
        ['label' => 'How do I list a product?', 'tab' => 'seller-list'],
        ['label' => 'Where do orders and payouts go?', 'tab' => 'seller-orders'],
      ],
    ],
  ];
  $extra = $copy[(string)($topic['id'] ?? '')] ?? [];
  $lead = (string)($extra['lead'] ?? ('Guides for ' . $label . ' on Talsora. Open an article for steps.'));
  $learn = (array)($extra['learn'] ?? []);
  if (!$learn) {
    foreach (array_slice($items, 0, 3) as $item) {
      $learn[] = (string)($item['label'] ?? '');
    }
  }
  $pills = (array)($extra['pills'] ?? []);
  if (!$pills) {
    foreach (array_slice($items, 0, 3) as $item) {
      $pills[] = ['label' => (string)($item['label'] ?? ''), 'tab' => (string)($item['tab'] ?? '')];
    }
  }
  $pills[] = ['label' => 'I have a different question', 'tab' => 'help'];
  echo '<article class="auth-legal-article" data-legal-panel="' . index_footer_tab_h($tab) . '"' . $show . '>';
  echo '<div class="hc-title-row"><h1>' . index_footer_tab_h($label) . '</h1><button type="button" class="hc-copy js-hc-copy">Copy link</button></div>';
  echo '<div class="hc-pills" role="navigation" aria-label="Related questions">';
  foreach ($pills as $pill) {
    $pTab = (string)($pill['tab'] ?? '');
    if ($pTab === '') {
      continue;
    }
    echo '<a class="hc-pill js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href($pTab, $addingAccount)) . '" data-index-tab="' . index_footer_tab_h($pTab) . '">' . index_footer_tab_h((string)($pill['label'] ?? '')) . '</a>';
  }
  echo '</div>';
  echo '<p class="auth-legal-lead">' . index_footer_tab_h($lead) . '</p>';
  if ($learn) {
    echo '<h2 class="hc-acc-heading">Learn how to</h2><ul class="hc-learn">';
    foreach ($learn as $line) {
      echo '<li>' . index_footer_tab_h((string)$line) . '</li>';
    }
    echo '</ul>';
  }
  echo '<div class="hc-topic-links">';
  foreach ($items as $item) {
    $child = (string)($item['tab'] ?? '');
    if ($child === '') {
      continue;
    }
    echo '<a class="js-index-tab hc-topic-child" href="' . index_footer_tab_h(index_footer_tab_href($child, $addingAccount)) . '" data-index-tab="' . index_footer_tab_h($child) . '">' . index_footer_tab_h((string)($item['label'] ?? $child)) . '</a>';
  }
  echo '</div>';
  index_help_render_helpful($tab);
  index_help_render_related($topic, $addingAccount, $tab);
  echo '</article>';
}

function index_feature_articles(): array
{
  return [
    'profile-tagged' => [
      'title' => 'Posts with your name on them',
      'lead' => 'Tags put your name on a post so people can find you from Profile → Tags.',
      'guides' => [
        [
          'title' => 'How do I view tags?',
          'heading' => 'View posts you are tagged in',
          'steps' => [
            'Click your profile picture to go to your profile.',
            'Open Tags.',
          ],
          'note' => 'Every post that still has your name on it can show there. Tap a post to see the caption, comments, and who else is tagged.',
        ],
        [
          'title' => 'How do I manage tags?',
          'heading' => 'Manage tags on your profile',
          'steps' => [
            'Open the tagged post, then the post menu.',
            'Remove the tag if you do not want it on your profile.',
          ],
          'note' => 'That does not delete the original post. Tag visibility follows Gear → Privacy.',
        ],
        [
          'title' => 'What are tags?',
          'heading' => 'What a tag is',
          'steps' => [
            'A tag names you on someone else\'s photo or video.',
            'Mentions in a caption or comment use @ and can also notify you.',
          ],
          'note' => 'Both can show in notifications.',
        ],
        [
          'title' => 'Find posts that name you',
          'heading' => 'View Talsora posts you\'re tagged in',
          'steps' => [
            'Click your profile picture to go to your profile.',
            'Open Tags.',
          ],
          'note' => 'You can choose to hide a tagged post from your profile. Note that you can also change who can tag you in your privacy settings.',
          'links' => [
            ['text' => 'hide a tagged post from your profile', 'hash' => 'hc-profile-tagged-g5'],
            ['text' => 'who can tag you', 'tab' => 'privacy-settings'],
          ],
        ],
        [
          'title' => 'Hide a tagged post from your profile',
          'heading' => 'Hide a tagged post',
          'steps' => [
            'Open the tagged post, then the post menu.',
            'Remove the tag, or tighten who can see tags in Gear → Privacy.',
          ],
          'note' => 'The post then no longer sits on your public Tags tab.',
        ],
        [
          'title' => 'Archive a post you shared',
          'heading' => 'Archive your own post',
          'steps' => [
            'Open a post you shared.',
            'Use Archive from the post menu.',
          ],
          'note' => 'Archived posts leave the public grid until you restore them.',
        ],
        [
          'title' => 'Remove yourself from a post someone tagged you in',
          'heading' => 'Remove your tag',
          'steps' => [
            'Open the post someone tagged you in.',
            'Open the post menu and remove your tag.',
          ],
          'note' => 'The poster still has the photo; your name is no longer on it.',
        ],
        [
          'title' => 'Who can tag or mention you',
          'heading' => 'Control who can tag or mention you',
          'steps' => [
            'Open Gear → Privacy.',
            'Set who can tag and mention you.',
          ],
          'note' => 'Friends and people you accept are the usual circle.',
        ],
        [
          'title' => 'Who can see tagged posts on your profile',
          'heading' => 'Who sees tagged posts',
          'steps' => [
            'Anyone who can see your Tags tab can open those posts.',
          ],
          'note' => 'They cannot if you removed the tag or hid it with privacy settings.',
        ],
        [
          'title' => 'Where to see archived content',
          'heading' => 'Open archived content',
          'steps' => [
            'Open Archive from Profile or Gear tools.',
            'Restore a post if you want it back on your profile.',
          ],
        ],
      ],
    ],
    'profile-edit' => [
      'title' => 'Editing Your Profile',
      'lead' => 'Photo, display name, username, bio, cover, and sign-in tools live on Profile and in Gear.',
      'guides' => [
        [
          'title' => 'How to change your profile information',
          'heading' => 'Change your profile information',
          'steps' => [
            'Open Profile.',
            'Edit your display name and About Me.',
            'Open Gear for username, password, and linked accounts.',
          ],
        ],
        [
          'title' => 'Add or change your profile picture',
          'heading' => 'Add or change your profile picture',
          'steps' => [
            'On Profile, tap your photo.',
            'Upload a new one.',
          ],
          'note' => 'That picture is what people see on posts, messages, and suggestions.',
        ],
        [
          'title' => 'Make your account more private',
          'heading' => 'Make your account more private',
          'steps' => [
            'Open Gear → Privacy.',
            'Set who can follow you, tag you, and message you.',
          ],
          'note' => 'Some posts still follow the audience you picked when you posted.',
        ],
        [
          'title' => 'Add a website or links',
          'heading' => 'Add a website or links',
          'steps' => [
            'Open Profile.',
            'Put a site or handle in About Me.',
          ],
          'note' => 'Publisher pages can also point people to Shop or a public page.',
        ],
        [
          'title' => 'Add a bio',
          'heading' => 'Add a bio',
          'steps' => [
            'Open Profile.',
            'Edit About Me. Keep it short. You can change it anytime.',
          ],
        ],
        [
          'title' => 'Cover slides',
          'heading' => 'Edit cover slides',
          'steps' => [
            'Open Profile edit.',
            'Add or rearrange the wide images at the top of Profile.',
          ],
        ],
        [
          'title' => 'Change your password',
          'heading' => 'Change your password',
          'steps' => [
            'Open Gear → password tools.',
            'Set a new password, then review Manage devices.',
          ],
          'note' => 'Sign out sessions you do not recognize.',
        ],
        [
          'title' => 'Your account settings',
          'heading' => 'Your account settings',
          'steps' => [
            'Open Gear.',
            'Use appearance (including Dark auto), devices, notifications, and Danger Zone.',
          ],
        ],
        [
          'title' => 'Extra sign-in protection',
          'heading' => 'Add extra sign-in protection',
          'steps' => [
            'Use a password only you know.',
            'Sign out other devices from Manage devices.',
          ],
          'note' => 'Do not share login codes in chat.',
        ],
        [
          'title' => 'Log out',
          'heading' => 'Log out',
          'steps' => [
            'Open Gear.',
            'Choose log out on this device, or log out all devices.',
          ],
        ],
        [
          'title' => 'Clear search history',
          'heading' => 'Clear search history',
          'steps' => [
            'Search on Home and Public remembers recent lookups on that device.',
            'Clear site data in the browser if you want that list gone.',
          ],
          'note' => 'You will be signed out there.',
        ],
      ],
    ],
    'profile-visibility' => [
      'title' => 'Who Can See Your Profile',
      'lead' => 'Profile privacy and each post\'s audience decide who finds you and your posts.',
      'paras' => [
        'Open Gear → Privacy. Friends and follows see more than people you have not accepted.',
        'A public post can still show in Discover. Tagged posts follow Tags settings on your profile.',
      ],
    ],
    'share-post' => [
      'title' => 'Share a Post',
      'lead' => 'Compose from Home. Photos, video, and captions stay on your profile and in the feed.',
      'paras' => [
        'From Home, open Compose. Add media or text, then post. Friends and follows see it in Circle. Discover (Popular) can surface new posts to people who do not follow you yet.',
        'You can also share an existing post with someone in Messages.',
      ],
    ],
    'share-effects' => [
      'title' => 'Add Effects and Filters',
      'lead' => 'Adjust how a photo or video looks before you post, add a moment, or make a clip.',
      'paras' => [
        'In Compose, pick the photo or video first. Use the tools on that screen to crop, cover, or change the look, then write a caption.',
        'Stories and reels use the same idea: edit on the way in, then share. You can still edit the caption later from the post menu if the post is yours.',
      ],
    ],
    'share-edit-delete' => [
      'title' => 'Edit and Delete Your Posts',
      'lead' => 'Your posts stay on your profile until you change or remove them.',
      'paras' => [
        'Open the post, then the post menu. Edit the caption or delete the post. Deleted posts leave the feed and your gallery.',
        'If you only need it out of sight, you can also archive it and open it later from Archive.',
      ],
    ],
    'share-other-networks' => [
      'title' => 'Send a Talsora link',
      'lead' => 'Copy a link or send a post in Messages. Talsora does not post for you on other apps.',
      'paras' => [
        'Use Share on a post to send it to a chat or copy the link. Paste that link anywhere you want.',
        'Clips and live rooms work the same way: share the link, not an automatic cross-post.',
      ],
    ],
    'share-tags' => [
      'title' => 'Tagging and Mentions',
      'lead' => 'Tags and @mentions keep names in the post without leaving the thread.',
      'paras' => [
        'Tag people in a photo or mention them in a caption or comment with @. They can open the post from notifications or from Tags on their profile.',
        'Mentions in comments stay on that post. You can remove a tag you do not want on your profile.',
      ],
    ],
    'explore-search' => [
      'title' => 'Search and Explore',
      'lead' => 'Find people, pages, and posts from Home search and Discover.',
      'paras' => [
        'Use the search field on Home or Public to look up a name or username. Open a profile from the result, then follow if you want them in Circle.',
        'Discover (Popular) is the explore surface: new posts, publisher pages, and suggestions.',
      ],
    ],
    'explore-activity' => [
      'title' => 'Activity, Hashtags and Place Pages',
      'lead' => 'Catch up in Notifications. Hashtags and places help people find a post.',
      'paras' => [
        'Notifications show likes, comments, tags, follows, and live starts. Open one to jump to the post or profile.',
        'Add a hashtag or a place in the caption when you post if you want the post easier to find in search. Publisher pages are public destinations people follow from Discover.',
      ],
    ],
    'explore-feed' => [
      'title' => 'How Talsora Feed Works',
      'lead' => 'Circle is friends and follows. Discover is new posts, publishers, and suggestions.',
      'paras' => [
        'Home opens on Circle. That feed is people you know and pages you follow. Switch to Discover (Popular) when you want something new.',
        'Suggestions sit beside the feed. Follow a few people or publishers so Circle is not empty on day one.',
      ],
    ],
    'explore-notes' => [
      'title' => 'Notes and extra context',
      'lead' => 'Talsora does not run a separate Notes or Community Notes product. Extra context stays on the post.',
      'paras' => [
        'Captions, comments, tags, and publisher pages are how extra context shows up next to a post.',
        'If something is harmful or wrong, use the post menu to report it, or message an admin from Help.',
      ],
    ],
    'msg-manage' => [
      'title' => 'Send, View and Manage Messages',
      'lead' => 'Open Messages for one-to-one chats, photos, typing, and reactions.',
      'paras' => [
        'Start a chat from Messages or from a profile. Send text and photos. You will see when the other person is typing.',
        'You can rename a contact, mute a thread, or leave it. Notifications catch you up if you step away.',
      ],
    ],
    'msg-groups' => [
      'title' => 'Group Chats',
      'lead' => 'Talk with more than one person in the same thread.',
      'paras' => [
        'Create a group from Messages, add people, and send the same kinds of notes you send one-to-one.',
        'Anyone in the group can read the thread. Leave the group if you no longer want those messages.',
      ],
    ],
    'msg-calls' => [
      'title' => 'Audio and Video Calls',
      'lead' => 'Call from a chat when you want a live conversation instead of typing.',
      'paras' => [
        'Open a one-to-one thread in Messages and start an audio or video call from that chat.',
        'You need camera and microphone permission on that device. End the call from the same screen when you are done.',
      ],
    ],
    'msg-requests' => [
      'title' => 'Message Requests',
      'lead' => 'People you do not already chat with may land in requests instead of your main inbox.',
      'paras' => [
        'Open Messages and look for requests. Accept to move the chat into your inbox, or ignore and delete it.',
        'Gear → Privacy controls who can message you. Block stops new requests from that account.',
      ],
    ],
    'msg-channels' => [
      'title' => 'Channels',
      'lead' => 'Publisher pages are the public channel. Group chats stay private.',
      'paras' => [
        'Follow a publisher to get their posts in Circle and Discover. That is the open channel for news and brands.',
        'Private updates stay in Messages or a group chat. Commerce brands use Shop listings plus messages with buyers.',
      ],
    ],
    'reels-create' => [
      'title' => 'Make a clip',
      'lead' => 'Clips are short video. Make one from Compose, then trim and add a caption.',
      'paras' => [
        'Pick a short video, set a cover if you want, and post it as a clip. It shows in Clips and on your profile.',
        'You can edit the caption after you post. Delete it from the reel menu if you do not want it up anymore.',
      ],
    ],
    'reels-manage' => [
      'title' => 'Your clips',
      'lead' => 'Your reels live on your profile with your other posts.',
      'paras' => [
        'Open Profile or Clips to find something you posted. Use the menu to edit, archive, or delete.',
        'Archived reels leave the public reel row until you restore them.',
      ],
    ],
    'reels-share' => [
      'title' => 'Send a clip link',
      'lead' => 'Share a reel link. Talsora does not auto-post to other apps.',
      'paras' => [
        'Use Share on the reel to send it in Messages or copy the link.',
        'Whoever has the link can open it on Talsora if the reel is public.',
      ],
    ],
    'reels-discover' => [
      'title' => 'Watch clips',
      'lead' => 'Open Clips to scroll short video. Discover can also surface new clips.',
      'paras' => [
        'Clips is a vertical feed. Watch, then make your own when you are ready.',
        'You do not need an audience on day one. Watch first, post later.',
      ],
    ],
    'reels-accounts' => [
      'title' => 'Publisher and Commerce Accounts',
      'lead' => 'Personal accounts post everyday reels. Publisher and seller (commerce) accounts are public pages with extra jobs.',
      'guides' => [
        [
          'title' => 'How do publishers use reels?',
          'heading' => 'Clips on a publisher page',
          'steps' => [
            'Sign in to the publisher account, not a personal one.',
            'Open Compose or Clips and post the same way as any other account.',
          ],
          'note' => 'People follow the public name from Discover. Full publisher steps live in Publisher.',
          'links' => [
            ['text' => 'Publisher', 'tab' => 'topic-publisher'],
          ],
        ],
        [
          'title' => 'How do sellers use reels?',
          'heading' => 'Clips on a commerce account',
          'steps' => [
            'Sign in to the commerce (seller) account.',
            'Post reels to show products, then keep the listing in Shop so people can check out.',
          ],
          'note' => 'Selling happens on a commerce account. See Seller for listings and orders.',
          'links' => [
            ['text' => 'Seller', 'tab' => 'topic-seller'],
          ],
        ],
      ],
    ],
    'edits-trim' => [
      'title' => 'Trim, Crop, and Cover',
      'lead' => 'Shorten a clip and pick what people see first before you post a reel or story.',
      'paras' => [
        'In Compose, choose the video, then trim the length and crop the frame. Set a cover still so the clip does not open on a blank frame.',
        'These tools stay on the media. They do not change the original file on your device.',
      ],
    ],
    'edits-save' => [
      'title' => 'Save a Draft',
      'lead' => 'Leave Compose and come back later if you are not ready to post.',
      'paras' => [
        'If you close Compose, start again from Home when you are ready. Finish the caption and post from the same flow.',
        'Nothing goes public until you post.',
      ],
    ],
    'stories-add' => [
      'title' => 'Add a moment',
      'lead' => 'Stories are lighter than posts. They last a short time, then they are gone.',
      'paras' => [
        'Add a moment from Home or the moment row. Pick a photo or short video and send it.',
        'Friends and followers can watch it from the row at the top while it is still up.',
      ],
    ],
    'stories-watch' => [
      'title' => 'Watch moments',
      'lead' => 'Open the story row on Home. Tap a ring to watch, then move to the next person.',
      'paras' => [
        'Stories play in order. You can pause, skip, or close the viewer anytime.',
        'Your own story sits first in the row so you can add another moment the same day.',
      ],
    ],
    'stories-highlights' => [
      'title' => 'Keep a story on your profile',
      'lead' => 'Stories expire. Save one to your profile if you want it to stay visible.',
      'paras' => [
        'Open your story while it is still up, then save it to your profile from the story tools.',
        'Remove it later from Profile if you do not want it there anymore.',
      ],
    ],
    'stories-privacy' => [
      'title' => 'Story Privacy and Replies',
      'lead' => 'Who can watch a story follows your account privacy. Replies can go to Messages.',
      'paras' => [
        'Gear → Privacy controls a lot of who sees you. A close-friends style limit is not a separate product here — use privacy and who you accept as friends.',
        'If someone replies to a story, look in Messages.',
      ],
    ],
    'live-watch' => [
      'title' => 'Watch Public Live',
      'lead' => 'Join a room that is on without opening a specific profile first.',
      'paras' => [
        'Open Live or Public Live to see rooms that are live now. Tap one to watch.',
        'You can leave anytime. Hosts end the room from Live Studio when they are done.',
      ],
    ],
    'live-host' => [
      'title' => 'Host from Live Studio',
      'lead' => 'Go live when you are ready. Start and end the room from Studio.',
      'paras' => [
        'Open Live Studio, allow camera and microphone, then start. Friends and followers can join. Public lives can also show in Public Live.',
        'End the live from Studio so the room closes cleanly.',
      ],
    ],
    'live-during' => [
      'title' => 'During a Live',
      'lead' => 'Watch, react, and leave when you want. The host stays in control of the room.',
      'paras' => [
        'Viewers can watch without posting. Use reactions if they are on for that room.',
        'If a live is a problem, leave and report it from Help or the room controls.',
      ],
    ],
    'login-reset' => [
      'title' => 'Reset your password',
      'lead' => 'Change a password you know from Gear. If you cannot sign in, use recovery from the login screen.',
      'paras' => [
        'Signed in: open Gear → password tools and set a new password. Other devices can be signed out from Manage devices.',
        'Signed out: use the recovery path on the login screen with the email or username on the account. Then sign in and review devices.',
      ],
    ],
    'login-cant' => [
      'title' => 'Can\'t log in',
      'lead' => 'Check the username, the account type (Personal, Publisher, or Commerce), and that the account was not deactivated or deleted.',
      'paras' => [
        'Use the same account type you signed up with. A publisher or commerce login is not the same as personal.',
        'If the account was deactivated, follow the reactivation path shown at sign-in. If it was deleted, that login cannot be used again.',
        'Still stuck? Sign in on another device if you can, or message an admin from Help after you get in.',
      ],
    ],
    'login-hacked' => [
      'title' => 'If you think your account was taken',
      'lead' => 'Change the password if you can still get in. Sign out other devices. Write Help if you cannot sign in.',
      'paras' => [
        'If you can sign in, open Gear → password tools, then Manage devices and end sessions you do not know.',
        'Do not send your password or login codes to anyone who messages you. Talsora staff will not ask for them in chat.',
        'If you cannot sign in, use recovery on the login screen, then message Help after you get back in.',
      ],
    ],
    'login-devices' => [
      'title' => 'Devices and extra protection',
      'lead' => 'See where you are signed in and end sessions you do not recognize.',
      'paras' => [
        'Gear → Manage devices lists sessions. Log out a device you do not use. Log out all devices if a phone is missing.',
        'Use a password only you know. Do not share login codes or passwords in chat or email.',
      ],
    ],
    'abuse-spam' => [
      'title' => 'Abuse, Spam and Scams',
      'lead' => 'Report the post, profile, or message. Do not send money or codes to someone who contacts you out of the blue.',
      'paras' => [
        'Use the post, profile, or chat menu to report. You can also message an admin from Help.',
        'Talsora staff will not ask you for your password. Treat unexpected payment or gift requests as a scam.',
        'Block or restrict the person in Gear and in Messages if you need space while a report is reviewed.',
      ],
    ],
    'secure-account' => [
      'title' => 'Secure your Talsora account',
      'lead' => 'A unique password, device review, and careful links keep the account yours.',
      'paras' => [
        'Change the password if you think someone else used it. Sign out other devices from Gear.',
        'Do not approve logins you did not start. Do not install unknown apps that ask for your Talsora password.',
      ],
    ],
    'signup-start' => [
      'title' => 'Signing Up and Getting Started',
      'lead' => 'Create an account, land on Home, then follow a few people so Circle has something to show.',
      'paras' => [
        'Click Log in or Try Talsora. Choose Personal, Publisher, or Commerce. Personal accounts must meet the age shown at sign-up (currently 21).',
        'Add a photo and display name on Profile. Open Discover (Popular) and follow people or publisher pages.',
      ],
    ],
    'signup-types' => [
      'title' => 'Pick an account type',
      'lead' => 'Personal is everyday use. Publisher is a public page. Seller (commerce) sells in Shop.',
      'guides' => [
        [
          'title' => 'What is a personal account?',
          'heading' => 'Personal',
          'steps' => [
            'Choose Personal at sign-up. You must meet the age shown (currently 21).',
            'Follow, post, message, watch, and shop. This is the everyday account.',
          ],
          'note' => 'You can add a publisher or seller account later without replacing this one. See Adding Accounts.',
          'links' => [
            ['text' => 'Adding Accounts', 'tab' => 'add-accounts'],
          ],
        ],
        [
          'title' => 'What is a publisher page?',
          'heading' => 'Publisher',
          'steps' => [
            'Choose Publisher at sign-up and pick a public name.',
            'People follow the page from Discover and Circle.',
          ],
          'note' => 'Names may need admin approval. Open Publisher for the full how-to.',
          'links' => [
            ['text' => 'Publisher', 'tab' => 'topic-publisher'],
          ],
        ],
        [
          'title' => 'What is a seller account?',
          'heading' => 'Seller (commerce)',
          'steps' => [
            'Choose Commerce at sign-up. That is the seller track.',
            'List products in Shop, take orders, and message buyers.',
          ],
          'note' => 'Brand names may need admin approval. Open Seller for listings, orders, and payouts.',
          'links' => [
            ['text' => 'Seller', 'tab' => 'topic-seller'],
          ],
        ],
      ],
    ],
    'gear-settings' => [
      'title' => 'Adjust Your Account Settings',
      'lead' => 'Gear is username, password, privacy, appearance, devices, and linked accounts.',
      'paras' => [
        'Open Profile → Gear. Account tools change how you look and sign in. Privacy changes who sees you. Appearance includes Dark auto and page color.',
      ],
    ],
    'account-download' => [
      'title' => 'Download your information',
      'lead' => 'Ask Help for a copy of your account data if you need it. This is not a self-serve dump in Gear yet.',
      'paras' => [
        'Message an admin from Help and say you want a copy of your information. Staff will tell you what can be provided.',
        'Do not send your password. Use the signed-in Help thread.',
      ],
    ],
    'account-deactivate' => [
      'title' => 'Deactivate or delete',
      'lead' => 'Deactivate hides the account. Delete removes it. Both live in Gear Danger Zone.',
      'paras' => [
        'Open Gear and use Danger Zone. Deactivate can be reversed from sign-in. Delete cannot bring the same login back.',
        'If you cannot get into Gear, write Help after you recover the account.',
      ],
    ],
    'notif-settings' => [
      'title' => 'Notification Settings',
      'lead' => 'Choose what can ping you: follows, messages, live, shop, and admin replies.',
      'paras' => [
        'Gear → notifications (or the alerts settings on your account) controls which events show up.',
        'You can still open Notifications in the app to catch up even if some pushes are off.',
      ],
    ],
    'add-accounts' => [
      'title' => 'Adding Accounts',
      'lead' => 'Keep a personal account and add a publisher or commerce account without logging the first one out for good.',
      'paras' => [
        'Use Add account from the account switcher or Gear. Sign in or register the extra account, then switch when you need that profile.',
        'Each account has its own posts, messages, and Gear.',
      ],
    ],
    'verified-badges' => [
      'title' => 'Verified Badges',
      'lead' => 'Talsora does not sell a public verification checkmark. Publisher and commerce names may need admin approval instead.',
      'paras' => [
        'If a page claims to be a brand, look at the account type and the content. Report impersonation from the profile menu or Help.',
      ],
    ],
    'accessibility' => [
      'title' => 'Accessibility',
      'lead' => 'Use your device text size, captions where video provides them, and appearance contrast you can read.',
      'paras' => [
        'Gear → Appearance lets you pick a page color and Dark auto. Larger system text follows the phone or computer setting.',
        'If a control is unusable, describe it in Help so an admin can look at it.',
      ],
    ],
    'about-ads' => [
      'title' => 'About Talsora ads and promotions',
      'lead' => 'You can use Talsora without a subscription fee. Shop listings and promotions may appear. We do not sell your name or email to advertisers.',
      'paras' => [
        'Activity may make shop or promo placements more relevant. Aggregated reports do not have to name you.',
        'Publisher and commerce accounts may post branded or selling content. That content must be honest and follow Terms.',
      ],
    ],
    'share-safely' => [
      'title' => 'Sharing Photos Safely',
      'lead' => 'Only post what you are willing to have on your profile and in the feed. Tags and shares can travel.',
      'paras' => [
        'Think before you post a location, a school, or a document. Remove a tag you do not want. Archive or delete a post that should not stay up.',
        'Do not send nudes or private photos to someone you do not trust. You cannot control a screenshot.',
      ],
    ],
    'safety-tips' => [
      'title' => 'Safety Tips',
      'lead' => 'People you only know online are still strangers. Privacy, block, and report are there for a reason.',
      'paras' => [
        'Do not share passwords, one-time codes, or ID photos in chat. Meet in public if you move a chat offline.',
        'Use Gear → Privacy so random accounts cannot reach you as easily. Message Help if you need a person.',
      ],
    ],
    'tips-parents' => [
      'title' => 'Tips for Parents',
      'lead' => 'Personal accounts must meet the age shown at sign-up (currently 21). This is not a kids\' product.',
      'paras' => [
        'If someone under that age has an account, use Help so staff can review it. Talk with family about what is public on a profile.',
        'Parents cannot silently open another adult\'s messages. Privacy and safety tools are on each account.',
      ],
    ],
    'authentic-self' => [
      'title' => 'Being yourself on Talsora',
      'lead' => 'Use a name and photo people can recognize if you want friends to find you. You do not have to post every day.',
      'paras' => [
        'Display name and About Me are yours to edit. Impersonating someone else can be reported and removed.',
        'You can watch first and post later. Gear controls how visible you are.',
      ],
    ],
    'deal-conflict' => [
      'title' => 'Ways to deal with conflict or abuse',
      'lead' => 'You can restrict, block, report, and leave a chat. You do not have to keep a conversation going.',
      'paras' => [
        'Mute or leave a group. Block a profile so they cannot follow or message as easily. Report the content.',
        'If you are in immediate danger, contact local emergency services. Help is for account and product issues.',
      ],
    ],
    'self-injury' => [
      'title' => 'Self-injury',
      'lead' => 'If you are in crisis in the US, call or text 988. If you are elsewhere, use local emergency services.',
      'paras' => [
        'Talsora is not a crisis hotline. Message Help if a post or account needs a safety review. We do not provide instructions or methods.',
        'You can mute, unfollow, or report content that is hard to see. Friends can also point someone to 988 or local help.',
      ],
    ],
    'eating-disorders' => [
      'title' => 'About eating disorders',
      'lead' => 'If you or someone you know is struggling, use medical and crisis resources in your country. In the US, 988 can help you find the next step.',
      'paras' => [
        'Report content that promotes harm. Talsora does not give treatment advice. Help can review an account or post you flag.',
      ],
    ],
    'law-enforcement' => [
      'title' => 'Information for law enforcement',
      'lead' => 'Valid legal process goes through official channels. This page is not a public lookup of private accounts.',
      'paras' => [
        'Law enforcement should use the lawful request path for the jurisdiction and the company that operates Talsora. Users who need help with an account should open Help.',
        'We do not publish a DIY guide to accessing someone else\'s messages.',
      ],
    ],
    'privacy-settings' => [
      'title' => 'Managing Your Privacy Settings',
      'lead' => 'Gear → Privacy is who can see you, follow you, and contact you.',
      'paras' => [
        'Open Gear → Privacy and read each control before you change it. Some posts still follow the audience you picked when you posted.',
        'Friends, follows, and blocked accounts all change who shows up in Circle and who can message you.',
      ],
    ],
    'privacy-audience' => [
      'title' => 'Who can see your content',
      'lead' => 'Profile visibility and each post\'s audience work together.',
      'paras' => [
        'A public post can appear in Discover. A tighter audience stays closer to people you accepted. Stories follow the same idea for a short time.',
      ],
    ],
    'privacy-block' => [
      'title' => 'Block an account',
      'lead' => 'Block stops that account from easily following or messaging you.',
      'paras' => [
        'Open the profile menu and block, or use Gear. They should not show up in your requests the same way after that.',
        'Unblock later from Gear if you change your mind.',
      ],
    ],
    'privacy-restrict' => [
      'title' => 'Restrict an account',
      'lead' => 'Restrict is a quieter limit than block. Use it when you want space without a full block.',
      'paras' => [
        'Open the profile or chat menu and restrict if that control is on the account. You can also block or leave the chat.',
        'Report the content if it breaks the rules. Message Help if you cannot find the control.',
      ],
    ],
    'report-content' => [
      'title' => 'Report content on Talsora',
      'lead' => 'Use the post, profile, live, or message menu, then send enough detail for a review.',
      'paras' => [
        'Pick the reason that fits. Do not use report to settle a dislike of a legal opinion. Illegal or harmful content should be reported.',
        'You can also write an admin from Help if the menu is not available.',
      ],
    ],
    'osa-au' => [
      'title' => 'Australia Online Safety Act',
      'lead' => 'If Australian online-safety rules apply to your report, use in-product report and Help so it can be routed.',
      'paras' => [
        'This is a product pointer, not legal advice. Keep copies of what you reported. Local regulators publish their own complaint paths.',
      ],
    ],
    'osa-uk' => [
      'title' => 'UK Online Safety Act — submitting a complaint',
      'lead' => 'Report in Talsora first. If you need a regulator complaint, follow the UK process those authorities publish.',
      'paras' => [
        'Help can take an account-level complaint. This page does not replace Ofcom or other official forms.',
      ],
    ],
    'jp-platform' => [
      'title' => 'Japan information-distribution platform rules',
      'lead' => 'If those rules apply, use report and Help. This is not a substitute for counsel in Japan.',
      'paras' => [
        'Describe the content, dates, and accounts involved when you write Help so a review can start.',
      ],
    ],
    'impersonation' => [
      'title' => 'Impersonation accounts',
      'lead' => 'Report a profile that pretends to be you or a brand you represent.',
      'paras' => [
        'Open the profile menu → report, and explain who you are. Publisher and commerce names may already be in an approval queue.',
        'We do not take over an account just because the names are similar. Clear impersonation is reviewed.',
      ],
    ],
    'community-guidelines' => [
      'title' => 'Community Guidelines',
      'lead' => 'Do not post illegal content, scams, sexual content involving minors, or abuse that targets people.',
      'paras' => [
        'Personal accounts must meet the age at sign-up (currently 21). Do not impersonate others. Do not sell what Shop and Terms do not allow.',
        'We may remove content, limit an account, or deactivate when these rules are broken. Appeals can go through Help.',
      ],
    ],
    'platform-policy' => [
      'title' => 'Platform Policy',
      'lead' => 'How Talsora runs accounts, automation, and enforcement on top of Terms.',
      'paras' => [
        'We use tools (including automated systems) to keep feeds, messages, Live, and Shop working and safer. We may store data on the infrastructure that runs the product.',
        'Repeated harm, evasion of a ban, or selling access to an account can end the account.',
      ],
    ],
    'cookies-policy' => [
      'title' => 'Cookies Policy',
      'lead' => 'Talsora uses cookies and similar storage to keep you signed in, remember appearance, and run the site.',
      'paras' => [
        'Session cookies keep a login. Appearance and Dark auto may use a cookie so Help still matches Gear after you sign out.',
        'You can clear site data in the browser. That will sign you out on that device.',
      ],
    ],
    'transparency' => [
      'title' => 'Transparency Center',
      'lead' => 'High-level view of how enforcement and government requests are handled. Not a public dump of private data.',
      'paras' => [
        'We may share information with providers who host the product, and when law requires it. Users see their own Gear, reports, and Help threads.',
        'Law enforcement must use lawful process. We do not publish a catalog of investigations.',
      ],
    ],
    'payments-terms' => [
      'title' => 'Shop money rules',
      'lead' => 'Shop checkout and seller payouts follow the payment tools Talsora is connected to.',
      'paras' => [
        'Buyers should confirm the listing, price, and delivery details before paying. Sellers must honor orders they accept.',
        'Disputes start with the seller in Messages, then Help if that fails. Card and payout rules are those of the payment processor.',
      ],
    ],
    'purchase-protection' => [
      'title' => 'Shop purchase protection',
      'lead' => 'If an order is not delivered as listed, contact the seller, then Help, then your payment provider if needed.',
      'paras' => [
        'Keep the order screen and messages. Talsora is not a bank. Chargebacks follow your card or wallet issuer.',
        'Sellers who do not fulfill orders can lose commerce access.',
      ],
    ],
    'pub-create' => [
      'title' => 'Create a publisher page',
      'lead' => 'A publisher page is a public Talsora channel. People follow it from Discover and Circle.',
      'guides' => [
        [
          'title' => 'How do I create a publisher account?',
          'heading' => 'Sign up as Publisher',
          'steps' => [
            'Click Try Talsora or Log in, then choose Publisher — not Personal.',
            'Pick a public publisher name from the list, or request one if you need a new name.',
            'Add a username, password, and photo so the page looks like a real destination.',
          ],
          'note' => 'Keep a personal account too if you want. Use Add account from Gear so you can switch without losing the first login.',
          'links' => [
            ['text' => 'Add account', 'tab' => 'add-accounts'],
          ],
        ],
        [
          'title' => 'What goes on a publisher profile?',
          'heading' => 'Set up the public page',
          'steps' => [
            'Open Profile and add a photo plus display name people will recognize.',
            'Write About Me so visitors know what the page posts.',
            'Post once you are approved so Discover has something to show.',
          ],
          'note' => 'Publisher pages stay public destinations. Privacy still lives in Gear → Privacy.',
        ],
      ],
    ],
    'pub-approval' => [
      'title' => 'Publisher name approval',
      'lead' => 'Some publisher names wait for an admin before the account can finish sign-up.',
      'guides' => [
        [
          'title' => 'Why is my name waiting?',
          'heading' => 'Admin approval',
          'steps' => [
            'Stay on the sign-up page after you request the name.',
            'Check back until the name is approved. Do not create a lookalike personal account to skip the queue.',
          ],
          'note' => 'If the request is rejected, pick another name or write Help. We do not sell a verification checkmark.',
          'links' => [
            ['text' => 'Help', 'tab' => 'help'],
          ],
        ],
        [
          'title' => 'The name is already taken',
          'heading' => 'Choose another public name',
          'steps' => [
            'Pick a different name from the publisher list, or request a new one.',
            'If someone is impersonating a real brand, report it from the profile menu or Help.',
          ],
          'note' => 'Registered publisher names cannot be reused. See Verified Badges for how Talsora treats public pages.',
          'links' => [
            ['text' => 'Verified Badges', 'tab' => 'verified-badges'],
          ],
        ],
      ],
    ],
    'pub-post' => [
      'title' => 'Post, reels, and Live as a publisher',
      'lead' => 'After you are signed in as the publisher, create the same way as any account — the audience is public followers.',
      'guides' => [
        [
          'title' => 'How do I post on the page?',
          'heading' => 'Share from the publisher account',
          'steps' => [
            'Switch to the publisher account if you also have a personal login.',
            'Open Compose to share a photo or video, or open Clips for a short clip.',
            'Go live from Live Studio when you want a room in real time.',
          ],
          'note' => 'Captions, tags, and mentions work the same. Selling still needs a seller (commerce) account — see Seller.',
          'links' => [
            ['text' => 'Seller', 'tab' => 'topic-seller'],
          ],
        ],
        [
          'title' => 'Can I keep a personal and a publisher page?',
          'heading' => 'Two accounts, one person',
          'steps' => [
            'Add the publisher account from Gear or the account switcher.',
            'Switch when you need the public page. Each login has its own posts and messages.',
          ],
          'note' => 'Do not mix private life and the public channel on the same profile if you want them separate.',
        ],
      ],
    ],
    'pub-discover' => [
      'title' => 'Be found on Discover',
      'lead' => 'Discover (Popular) is how new people find publisher pages they do not already follow.',
      'guides' => [
        [
          'title' => 'How do people find my page?',
          'heading' => 'Show up in Discover',
          'steps' => [
            'Post on a regular cadence so Discover has recent work to surface.',
            'Use a clear name, photo, and About Me so a new visitor knows what the page is.',
            'Ask people to follow from Discover or from a post on the page.',
          ],
          'note' => 'Circle is friends and follows. Discover is new posts, publishers, and suggestions. See Popular.',
          'links' => [
            ['text' => 'Popular', 'tab' => 'popular'],
          ],
        ],
      ],
    ],
    'seller-create' => [
      'title' => 'Create a seller account',
      'lead' => 'Sellers use a commerce account. That is the Shop storefront login — not a personal account and not a publisher-only page.',
      'guides' => [
        [
          'title' => 'How do I become a seller?',
          'heading' => 'Sign up as Commerce',
          'steps' => [
            'Click Try Talsora or Log in, then choose Commerce (seller).',
            'Pick an existing commerce brand, or click Add name and wait for admin approval.',
            'Finish username, password, and photo after the brand is approved.',
          ],
          'note' => 'Stay on the sign-up page while a brand request is pending. You can keep a personal account and add this seller login from Gear.',
          'links' => [
            ['text' => 'Add account', 'tab' => 'add-accounts'],
          ],
        ],
        [
          'title' => 'Publisher or seller?',
          'heading' => 'Pick the right track',
          'steps' => [
            'Use Publisher for a news or brand page people follow.',
            'Use Commerce when you need to list products and take orders in Shop.',
          ],
          'note' => 'A publisher page can exist next to Shop. Checkout still happens on the seller account. See Publisher if you only need a public channel.',
          'links' => [
            ['text' => 'Publisher', 'tab' => 'topic-publisher'],
          ],
        ],
      ],
    ],
    'seller-list' => [
      'title' => 'List products in Shop',
      'lead' => 'Listings live in Shop on the commerce account. Personal shoppers browse them; you manage what is for sale.',
      'guides' => [
        [
          'title' => 'How do I list a product?',
          'heading' => 'Add a listing',
          'steps' => [
            'Sign in to the commerce (seller) account.',
            'Open Shop. Use the commerce tools there to add a product, price, and details.',
            'Keep inventory and photos accurate so the listing matches what you ship.',
          ],
          'note' => 'Shoppers find you from Shop, not from a personal profile. You can still post reels that point people to the listing.',
        ],
        [
          'title' => 'Where do shoppers see the listing?',
          'heading' => 'How Shop works for buyers',
          'steps' => [
            'Personal accounts open Shop, save favorites, add a delivery location, and check out.',
            'They can message you about an order from Messages.',
          ],
          'note' => 'The Shop overview for shoppers is in Shop. Purchase problems use Shop purchase protection.',
          'links' => [
            ['text' => 'Shop', 'tab' => 'shop'],
            ['text' => 'Shop purchase protection', 'tab' => 'purchase-protection'],
          ],
        ],
      ],
    ],
    'seller-orders' => [
      'title' => 'Orders, messages, and payouts',
      'lead' => 'Honor every order you accept. Talk to the buyer in Messages. Payouts follow the payment tools Talsora is connected to.',
      'guides' => [
        [
          'title' => 'How do I handle an order?',
          'heading' => 'Fulfill what you listed',
          'steps' => [
            'Watch Shop and Messages for new orders and buyer questions.',
            'Ship or deliver what the listing promised. Keep a record of the order screen.',
            'If something is wrong, work it out with the buyer first.',
          ],
          'note' => 'If you cannot agree, the buyer can open Help, then their card or wallet issuer. Sellers who do not fulfill can lose commerce access.',
          'links' => [
            ['text' => 'Help', 'tab' => 'help'],
          ],
        ],
        [
          'title' => 'When do I get paid?',
          'heading' => 'Checkout and payouts',
          'steps' => [
            'Checkout uses the payment tools connected to Talsora.',
            'Payout timing and fees are those of the processor, not a separate Talsora bank.',
          ],
          'note' => 'Read Checkout and payouts before you list. Talsora is not a bank.',
          'links' => [
            ['text' => 'Checkout and payouts', 'tab' => 'payments-terms'],
          ],
        ],
      ],
    ],
    'seller-rules' => [
      'title' => 'Honest listings and Shop rules',
      'lead' => 'Listings must match the product. Scams, banned goods, and fake brands are not allowed.',
      'guides' => [
        [
          'title' => 'What must a listing include?',
          'heading' => 'Be clear and honest',
          'steps' => [
            'Show the real product, price, and delivery details before checkout.',
            'Do not impersonate another brand. Commerce names go through admin approval for a reason.',
          ],
          'note' => 'Selling content must follow Terms and Community Guidelines. Illegal or misleading listings can end the account.',
          'links' => [
            ['text' => 'Terms', 'tab' => 'terms'],
            ['text' => 'Community Guidelines', 'tab' => 'community-guidelines'],
          ],
        ],
      ],
    ],
  ];
}

function index_help_tab_title(string $tab): string
{
  $tabs = index_footer_tabs();
  if (isset($tabs[$tab])) {
    return (string)$tabs[$tab];
  }
  $articles = index_feature_articles();
  if (isset($articles[$tab]['title'])) {
    return (string)$articles[$tab]['title'];
  }
  $topic = index_help_topic_for_tab($tab);
  if ($topic !== null) {
    return (string)($topic['label'] ?? 'Talsora');
  }
  return 'Talsora';
}

function index_help_all_tab_keys(): array
{
  return array_merge(array_keys(index_footer_tabs()), array_keys(index_feature_articles()), index_help_topic_keys());
}

function index_help_all_tab_titles(): array
{
  $out = index_footer_tabs();
  foreach (index_feature_articles() as $key => $article) {
    $out[$key] = (string)$article['title'];
  }
  foreach (index_help_each_topic() as $topic) {
    $id = index_help_topic_tab($topic);
    if ($id !== '') {
      $out[$id] = (string)($topic['label'] ?? $id);
    }
  }
  return $out;
}

function index_footer_tab_group_label(string $tab): string
{
  foreach (index_footer_tab_groups() as $group) {
    if (in_array($tab, $group['items'], true)) {
      return (string)$group['label'];
    }
  }
  foreach (index_help_nav_sections() as $section) {
    foreach ((array)($section['topics'] ?? []) as $topic) {
      if (index_help_topic_tab($topic) === $tab) {
        return (string)$topic['label'];
      }
      foreach ((array)($topic['items'] ?? []) as $item) {
        if (($item['tab'] ?? '') === $tab) {
          return (string)$topic['label'];
        }
      }
    }
  }
  if (array_key_exists($tab, index_feature_articles())) {
    return 'Talsora Features';
  }
  return 'Talsora';
}

function index_help_crumb_text(string $tab): string
{
  foreach (index_help_nav_sections() as $section) {
    foreach ((array)($section['topics'] ?? []) as $topic) {
      if (index_help_topic_tab($topic) === $tab) {
        return (string)($section['label'] ?? '') . ' › ' . (string)($topic['label'] ?? '');
      }
      foreach ((array)($topic['items'] ?? []) as $item) {
        if (($item['tab'] ?? '') === $tab) {
          return (string)($section['label'] ?? '') . ' › ' . (string)($topic['label'] ?? '');
        }
      }
    }
  }
  return index_footer_tab_group_label($tab);
}

function index_help_crumb_map(): array
{
  $map = [];
  foreach (index_help_all_tab_keys() as $key) {
    $map[$key] = index_help_crumb_text($key);
  }
  return $map;
}

function index_render_footer_tab_nav(string $activeTab, bool $addingAccount = false): void
{
  echo '<nav aria-label="Talsora">';
  foreach (index_footer_tabs() as $key => $label) {
    $href = index_footer_tab_href($key, $addingAccount);
    $active = $activeTab === $key ? ' is-active' : '';
    echo '<a class="js-index-tab' . $active . '" href="' . index_footer_tab_h($href) . '" data-index-tab="' . index_footer_tab_h($key) . '">' . index_footer_tab_h($label) . '</a>';
  }
  echo '</nav>';
}

function index_render_help_center_nav(string $activeTab, bool $addingAccount = false): void
{
  $tabs = index_footer_tabs();
  $openSection = '';
  foreach (index_help_nav_sections() as $section) {
    foreach ((array)($section['topics'] ?? []) as $topic) {
      if (index_help_topic_tab($topic) === $activeTab) {
        $openSection = (string)($section['label'] ?? '');
        break 2;
      }
      foreach ((array)($topic['items'] ?? []) as $item) {
        if (($item['tab'] ?? '') === $activeTab) {
          $openSection = (string)($section['label'] ?? '');
          break 3;
        }
      }
    }
  }
  echo '<nav class="hc-nav" id="hcNav" aria-label="Talsora">';
  foreach (index_help_nav_sections() as $section) {
    $label = (string)($section['label'] ?? '');
    $sectionOpen = $openSection === $label || ($activeTab === '' && $label === 'Talsora Features');
    echo '<details class="hc-nav-group hc-nav-features"' . ($sectionOpen ? ' open' : '') . '>';
    echo '<summary>' . index_footer_tab_h($label) . '</summary>';
    echo '<div class="hc-nav-feature-list">';
    foreach ((array)($section['topics'] ?? []) as $topic) {
      $topicId = (string)($topic['id'] ?? '');
      $icon = (string)($topic['icon'] ?? '');
      $topicTab = index_help_topic_tab($topic);
      $topicOpen = $topicTab !== '' && $topicTab === $activeTab;
      foreach ((array)($topic['items'] ?? []) as $item) {
        if (($item['tab'] ?? '') === $activeTab) {
          $topicOpen = true;
          break;
        }
      }
      echo '<details class="hc-nav-topic" data-topic="' . index_footer_tab_h($topicId) . '"' . ($topicOpen ? ' open' : '') . '>';
      echo '<summary><span class="hc-nav-ico hc-nav-ico-' . index_footer_tab_h($icon) . '" aria-hidden="true"></span>';
      if ($topicTab !== '') {
        echo '<a class="js-index-tab hc-topic-link' . ($activeTab === $topicTab ? ' is-active' : '') . '" href="' . index_footer_tab_h(index_footer_tab_href($topicTab, $addingAccount)) . '" data-index-tab="' . index_footer_tab_h($topicTab) . '">' . index_footer_tab_h((string)($topic['label'] ?? '')) . '</a>';
      } else {
        echo '<span>' . index_footer_tab_h((string)($topic['label'] ?? '')) . '</span>';
      }
      echo '</summary>';
      echo '<ul>';
      foreach ((array)($topic['items'] ?? []) as $item) {
        $key = (string)($item['tab'] ?? '');
        $itemLabel = (string)($item['label'] ?? $key);
        $href = index_footer_tab_href($key, $addingAccount);
        $active = $activeTab === $key ? ' is-active' : '';
        echo '<li><a class="js-index-tab' . $active . '" href="' . index_footer_tab_h($href) . '" data-index-tab="' . index_footer_tab_h($key) . '">' . index_footer_tab_h($itemLabel) . '</a></li>';
      }
      echo '</ul></details>';
    }
    echo '</div></details>';
  }
  foreach (index_footer_tab_groups() as $group) {
    $open = $activeTab !== '' && in_array($activeTab, $group['items'], true);
    echo '<details class="hc-nav-group"' . ($open || $activeTab === '' ? ' open' : '') . '>';
    echo '<summary>' . index_footer_tab_h((string)$group['label']) . '</summary>';
    echo '<ul>';
    foreach ($group['items'] as $key) {
      $itemLabel = (string)($tabs[$key] ?? $key);
      $href = index_footer_tab_href($key, $addingAccount);
      $active = $activeTab === $key ? ' is-active' : '';
      echo '<li><a class="js-index-tab' . $active . '" href="' . index_footer_tab_h($href) . '" data-index-tab="' . index_footer_tab_h($key) . '">' . index_footer_tab_h($itemLabel) . '</a></li>';
    }
    echo '</ul></details>';
  }
  echo '</nav>';
}

function index_help_story_inline_style(): string
{
  $bg = strtolower(trim((string)($GLOBALS['__MSB_INDEX_STORY_BG'] ?? '')));
  if (!preg_match('/^#[0-9a-f]{6}$/', $bg)) {
    return '';
  }
  $text = strtolower(trim((string)($GLOBALS['__MSB_INDEX_STORY_TEXT'] ?? '')));
  $gold = strtolower(trim((string)($GLOBALS['__MSB_INDEX_STORY_GOLD'] ?? '')));
  $css = 'background-color:' . $bg . ' !important;--hc-story-bg:' . $bg . ';--msb-palette-bg:' . $bg . ';';
  if (preg_match('/^#[0-9a-f]{6}$/', $text)) {
    $css .= 'color:' . $text . ' !important;--hc-story-text:' . $text . ';';
  }
  if (preg_match('/^#[0-9a-f]{6}$/', $gold)) {
    $css .= '--hc-story-gold:' . $gold . ';';
  }
  return ' style="' . $css . '"';
}

function index_render_about_try_link(bool $loggedIn, bool $addingAccount): void
{
  if ($loggedIn) {
    echo '<a class="hc-story-try" href="home.php?tab=for-you">Open Home</a>';
    return;
  }
  $href = $addingAccount ? 'index.php?add_account=1' : 'index.php';
  echo '<a class="hc-story-try js-hc-login" href="' . index_footer_tab_h($href) . '">Try Talsora</a>';
}

function index_help_map_ext_icon(): string
{
  return '<svg class="hc-map-ext" viewBox="0 0 12 12" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" d="M4 2.2h5.8V8M9.6 2.4L2.4 9.6"/></svg>';
}

function index_render_about_sitemap(bool $loggedIn, bool $addingAccount): void
{
  echo '<nav class="hc-about-map" id="hc-about-map" aria-label="Talsora directories">';
  echo '<div class="hc-about-map-grid">';
  foreach (index_help_nav_sections() as $section) {
    echo '<div class="hc-about-map-col">';
    echo '<h2>' . index_footer_tab_h((string)($section['label'] ?? '')) . '</h2>';
    echo '<ul>';
    foreach ((array)($section['topics'] ?? []) as $topic) {
      $tab = index_help_topic_tab($topic);
      $label = (string)($topic['label'] ?? '');
      if ($tab === '' || $label === '') {
        continue;
      }
      echo '<li><a class="hc-map-link js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href($tab, $addingAccount)) . '" data-index-tab="' . index_footer_tab_h($tab) . '">' . index_footer_tab_h($label) . '</a></li>';
    }
    echo '</ul></div>';
  }
  foreach (index_footer_tab_groups() as $group) {
    echo '<div class="hc-about-map-col">';
    echo '<h2>' . index_footer_tab_h((string)($group['label'] ?? '')) . '</h2>';
    echo '<ul>';
    $tabs = index_footer_tabs();
    foreach ((array)($group['items'] ?? []) as $key) {
      $label = (string)($tabs[$key] ?? $key);
      echo '<li><a class="hc-map-link js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href($key, $addingAccount)) . '" data-index-tab="' . index_footer_tab_h($key) . '">' . index_footer_tab_h($label) . '</a></li>';
    }
    echo '</ul></div>';
  }
  echo '</div></nav>';
}

function index_render_about_story(array $panel, string $activeTab, bool $loggedIn, bool $addingAccount = false): void
{
  $show = $activeTab === 'about' ? '' : ' hidden';
  $heroSrc = (string)($panel['hero_src'] ?? '');
  $heroAlt = (string)($panel['hero_alt'] ?? '');
  $sections = (array)($panel['sections'] ?? []);
  $destinations = (array)($panel['destinations'] ?? []);
  $features = (array)($panel['features'] ?? []);
  $steps = (array)($panel['steps'] ?? []);
  $accounts = (array)($panel['accounts'] ?? []);
  echo '<article class="auth-legal-article hc-about-story" data-legal-panel="about"' . $show . index_help_story_inline_style() . '>';
  echo '<section class="hc-story-hero" id="hc-about-start">';
  if ($heroSrc !== '') {
    echo '<img src="' . index_footer_tab_h($heroSrc) . '" alt="' . index_footer_tab_h($heroAlt) . '">';
  }
  echo '<div class="hc-story-hero-copy">';
  echo '<p class="hc-story-kicker">' . index_footer_tab_h((string)($panel['kicker'] ?? '')) . '</p>';
  echo '<h1>Share the day.<br>Follow the world.<br>Shop when you want.</h1>';
  echo '<p class="hc-story-lead">' . index_footer_tab_h((string)($panel['lead'] ?? '')) . '</p>';
  echo '<div class="hc-story-hero-actions">';
  index_render_about_try_link($loggedIn, $addingAccount);
  echo '<a class="hc-story-scroll" href="#hc-about-has">see what Talsora has</a>';
  echo '</div></div></section>';
  echo '<nav class="hc-story-progress" aria-label="On this page">';
  echo '<a href="#hc-about-start" class="is-active">Start</a>';
  echo '<a href="#hc-about-has">Has</a>';
  echo '<a href="#hc-about-use">Use</a>';
  $n = 0;
  foreach ($sections as $_) {
    $n++;
    echo '<a href="#hc-about-' . $n . '">' . str_pad((string)$n, 2, '0', STR_PAD_LEFT) . '</a>';
  }
  echo '</nav>';

  if ($destinations) {
    echo '<section class="hc-dest-grid" aria-label="Explore Talsora">';
    foreach ($destinations as $card) {
      $href = trim((string)($card['href'] ?? ''));
      $tab = trim((string)($card['tab'] ?? ''));
      $class = 'hc-dest-card';
      if ($tab !== '') {
        $href = index_footer_tab_href($tab, $addingAccount);
        $class .= ' js-index-tab';
      }
      echo '<a class="' . $class . '" href="' . index_footer_tab_h($href) . '"' . ($tab !== '' ? ' data-index-tab="' . index_footer_tab_h($tab) . '"' : '') . '>';
      echo '<p class="hc-story-kicker">' . index_footer_tab_h((string)($card['kicker'] ?? '')) . '</p>';
      echo '<h2>' . index_footer_tab_h((string)($card['heading'] ?? '')) . '</h2>';
      echo '<p>' . index_footer_tab_h((string)($card['text'] ?? '')) . '</p>';
      echo '</a>';
    }
    echo '</section>';
  }

  if ($features) {
    echo '<section class="hc-about-band" id="hc-about-has">';
    echo '<p class="hc-story-kicker">WHAT TALSORA HAS</p>';
    echo '<h2 class="hc-about-band-title">Everything in one product</h2>';
    echo '<p class="hc-about-band-lead">Home, stories, reels, live, messages, shop, and Gear — not a stack of separate apps.</p>';
    echo '<div class="hc-feat-grid">';
    foreach ($features as $feat) {
      echo '<article class="hc-feat-card">';
      echo '<h3>' . index_footer_tab_h((string)($feat['title'] ?? '')) . '</h3>';
      echo '<p>' . index_footer_tab_h((string)($feat['text'] ?? '')) . '</p>';
      echo '</article>';
    }
    echo '</div></section>';
  }

  $i = 0;
  foreach ($sections as $section) {
    $i++;
    $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
    $flip = $i % 2 === 0 ? ' is-flip' : '';
    echo '<section class="hc-story-block' . $flip . '" id="hc-about-' . $i . '">';
    echo '<span class="hc-story-num" aria-hidden="true">' . $num . '</span>';
    echo '<div class="hc-story-copy">';
    echo '<p class="hc-story-kicker">' . index_footer_tab_h((string)($section['kicker'] ?? '')) . '</p>';
    echo '<h2>' . index_footer_tab_h((string)($section['heading'] ?? '')) . '</h2>';
    foreach ((array)($section['paras'] ?? []) as $para) {
      echo '<p>' . index_footer_tab_h((string)$para) . '</p>';
    }
    $cta = (array)($section['cta'] ?? []);
    $ctaTab = trim((string)($cta['tab'] ?? ''));
    $ctaLabel = trim((string)($cta['label'] ?? ''));
    if ($ctaTab !== '' && $ctaLabel !== '') {
      echo '<a class="hc-story-more js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href($ctaTab, $addingAccount)) . '" data-index-tab="' . index_footer_tab_h($ctaTab) . '">' . index_footer_tab_h($ctaLabel) . '</a>';
    }
    echo '</div>';
    $video = trim((string)($section['video'] ?? ''));
    $image = trim((string)($section['image'] ?? ''));
    $alt = (string)($section['alt'] ?? '');
    if ($video !== '') {
      echo '<figure class="hc-story-photo hc-about-reel">';
      echo '<video class="hc-about-reel-video" src="' . index_footer_tab_h($video) . '"';
      if ($image !== '') {
        echo ' poster="' . index_footer_tab_h($image) . '"';
      }
      echo ' muted loop playsinline preload="metadata" aria-label="' . index_footer_tab_h($alt !== '' ? $alt : 'Talsora reel') . '"></video>';
      echo '<figcaption class="hc-about-reel-meta"><span class="hc-about-reel-kicker">CLIPS</span>Short video</figcaption>';
      echo '</figure>';
    } elseif ($image !== '') {
      echo '<figure class="hc-story-photo"><img src="' . index_footer_tab_h($image) . '" alt="' . index_footer_tab_h($alt) . '" loading="lazy"></figure>';
    }
    echo '</section>';
  }

  if ($steps) {
    echo '<section class="hc-about-band" id="hc-about-use">';
    echo '<p class="hc-story-kicker">HOW TO START</p>';
    echo '<h2 class="hc-about-band-title">A new user\'s first day</h2>';
    echo '<p class="hc-about-band-lead">You can watch first. Post when you want. Follow people and pages so Home has something to show.</p>';
    echo '<ol class="hc-step-list">';
    foreach ($steps as $step) {
      echo '<li><strong>' . index_footer_tab_h((string)($step['title'] ?? '')) . '</strong>';
      echo '<p>' . index_footer_tab_h((string)($step['text'] ?? '')) . '</p></li>';
    }
    echo '</ol>';
    echo '<div class="hc-story-hero-actions">';
    index_render_about_try_link($loggedIn, $addingAccount);
    echo '</div></section>';
  }

  if ($accounts) {
    echo '<section class="hc-about-band" id="hc-about-accounts">';
    echo '<p class="hc-story-kicker">ACCOUNTS</p>';
    echo '<h2 class="hc-about-band-title">Pick the account that fits</h2>';
    echo '<div class="hc-acct-grid">';
    foreach ($accounts as $acct) {
      echo '<article class="hc-feat-card">';
      echo '<h3>' . index_footer_tab_h((string)($acct['title'] ?? '')) . '</h3>';
      echo '<p>' . index_footer_tab_h((string)($acct['text'] ?? '')) . '</p>';
      echo '</article>';
    }
    echo '</div></section>';
  }

  index_render_about_sitemap($loggedIn, $addingAccount);
  echo '</article>';
}

function index_render_legal_panels(string $activeTab, bool $loggedIn, bool $addingAccount = false): void
{
  $panels = [
    'about' => [
      'title' => 'Share the day. Follow the world. Shop when you want.',
      'kicker' => 'ABOUT TALSORA',
      'lead' => 'Talsora is one product for people, publishers, and shops. Post, follow, watch, message, go live, and check out — then grow into more when you are ready.',
      'hero_src' => 'https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=2000&q=80',
      'hero_alt' => 'Open landscape at first light',
      'destinations' => [
        [
          'kicker' => 'FEATURES',
          'heading' => 'See what Talsora has',
          'text' => 'Home, stories, reels, live, messages, shop, profile, and Gear — all in one place.',
          'href' => '#hc-about-has',
        ],
        [
          'kicker' => 'NEW HERE',
          'heading' => 'How a new user starts',
          'text' => 'Create an account, land on Home, follow a few people or pages, then post when you are ready.',
          'href' => '#hc-about-use',
        ],
        [
          'kicker' => 'SAFETY',
          'heading' => 'Privacy, Gear, and Help',
          'text' => 'Control who sees you, how the product looks, and reach an admin when something is stuck.',
          'tab' => 'help',
        ],
      ],
      'features' => [
        ['title' => 'Home', 'text' => 'Circle is friends and follows. Discover (Popular) is new posts, publishers, and suggestions.'],
        ['title' => 'Moments', 'text' => 'Casual, short-lived moments from the day. Watch from the story row. Add your own when you want.'],
        ['title' => 'Posts', 'text' => 'Photos, video, and captions that stay on your profile and in the feed. React, comment, bookmark, share.'],
        ['title' => 'Clips', 'text' => 'Short video to watch and make. A fast way to see what people are into right now.'],
        ['title' => 'Live', 'text' => 'Watch public lives without opening a profile first, or host from Live Studio after you sign in.'],
        ['title' => 'Messages', 'text' => 'One-to-one or group. Photos, typing, reactions — a conversation that stays between you.'],
        ['title' => 'Shop', 'text' => 'Personal accounts browse, save, and check out. Commerce accounts list products.'],
        ['title' => 'Profile & Gear', 'text' => 'Gallery, Tags, About Me, Favorites. Gear is privacy, appearance, devices, password, and linked accounts.'],
      ],
      'steps' => [
        ['title' => 'Create an account', 'text' => 'Click Try Talsora or Log in. Choose Personal, Publisher, or Commerce. Personal accounts must meet the age shown at sign-up (currently 21). Publisher names and commerce brands may need admin approval.'],
        ['title' => 'Open Home', 'text' => 'You land on Circle. Add a photo and display name on Profile so people can recognize you.'],
        ['title' => 'Follow people and pages', 'text' => 'Switch to Discover (Popular). Follow friends, public pages, and publishers. That fills Circle.'],
        ['title' => 'Share when you are ready', 'text' => 'Compose a post, add a moment, make a clip, or go live. You can watch first — nothing requires an audience on day one.'],
        ['title' => 'Talk, shop, and tune Gear', 'text' => 'Messages for private chat. Shop to browse or sell. Gear for privacy, Dark auto, password, and devices. Help if you need an admin.'],
      ],
      'accounts' => [
        ['title' => 'Personal', 'text' => 'Everyday life with friends and family. Follow, post, message, watch, and shop. Age 21+ at sign-up.'],
        ['title' => 'Publisher', 'text' => 'A public page for a brand, newsroom, or voice. People follow you in Discover and Circle. Names may need admin approval.'],
        ['title' => 'Commerce', 'text' => 'Sell in Shop. List products, take orders, and message buyers. Brand names may need admin approval.'],
      ],
      'sections' => [
        [
          'kicker' => 'POSTS & MOMENTS',
          'heading' => 'Share the day with people who already know you',
          'image' => 'https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=900&h=1200&q=80',
          'alt' => 'Open landscape',
          'paras' => [
            'Posts stay on your profile and in Home. Moments are lighter — they last a short time, then they leave the row. Compose from Home. Watch moments from the row at the top.',
            'Friends are people you know. Follow is for public pages and publishers. Mentions and tags keep names in the conversation without leaving the post.',
          ],
          'cta' => ['label' => 'How Home works', 'tab' => 'popular'],
        ],
        [
          'kicker' => 'CLIPS & LIVE',
          'heading' => 'Watch, create, and go live',
          'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&h=1200&q=80',
          'alt' => 'Light over water',
          'paras' => [
            'Clips are short videos — scroll, watch, then make your own. Live is real time. Public Live is the open door: join a room that is on without opening a specific profile.',
            'When you are ready to host, open Live Studio, start a live, and end it from Studio when you are done. Friends and followers can join; public lives can also show in Public Live.',
          ],
          'cta' => ['label' => 'How Live works', 'tab' => 'live'],
        ],
        [
          'kicker' => 'MESSAGES',
          'heading' => 'Start a conversation that stays between you',
          'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&h=1200&q=80',
          'alt' => 'A quiet path for private talk',
          'paras' => [
            'Open Messages to talk one-to-one or in a group. Send photos and notes. See when someone is typing. React without leaving the thread.',
            'Notifications catch you up. Bookmarks save a post to open later. Your circle stays close without putting every message on the feed.',
          ],
          'cta' => ['label' => 'Get help', 'tab' => 'help'],
        ],
        [
          'kicker' => 'SHOP',
          'heading' => 'Buy and sell without leaving Talsora',
          'image' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=900&h=1200&q=80',
          'alt' => 'Shopping and public pages',
          'paras' => [
            'Personal accounts browse listings, save favorites, add a delivery location, and check out. Commerce accounts list products and manage orders.',
            'Publisher pages are still for news and brands. Selling happens on a commerce account. Gear is where you change privacy, appearance, and linked accounts. Policy and Terms live in this Help Center.',
          ],
          'cta' => ['label' => 'How Shop works', 'tab' => 'shop'],
        ],
      ],
    ],
    'guidance' => [
      'title' => 'Guidance',
      'lead' => 'How Talsora works for a new person, what is in the product, and how to reach an admin if something is stuck.',
      'sections' => [
        [
          'heading' => 'What Talsora has',
          'paras' => [
            'Talsora is one product, not a stack of separate apps. After you sign in you can use:',
          ],
          'list' => [
            'Home — Circle (friends and follows) and Discover / Popular (new posts, publishers, suggestions).',
            'Profile — your posts, Gallery, Tags, About Me, Favorites, and Gear.',
            'Stories — short-lived moments in the story row.',
            'Clips — short video to watch and make.',
            'Live — watch Public Live or host from Live Studio.',
            'Messages — one-to-one and group chats, with reactions and typing.',
            'Shop — browse, save, and check out on a personal account; list products on a commerce account.',
            'Friends and follows — people you know, plus public pages and publishers.',
            'Notifications and bookmarks — catch up and save posts to open later.',
            'Gear — privacy, security, appearance (including Dark auto), devices, password, and linked accounts.',
          ],
        ],
        [
          'heading' => 'How a new user starts',
          'paras' => [
            '1. Click Log in and choose Personal, Publisher, or Commerce. Personal accounts must meet the age shown at sign-up (currently 21). Publisher names and commerce brands may need admin approval.',
            '2. Sign in. You land on Home. Add a photo and display name on Profile so people can recognize you.',
            '3. Open Discover (Popular) and follow a few people or publisher pages. That fills Circle.',
            '4. Compose a post or add a moment when you want. You can watch first. Open Messages to talk to someone, Clips for short video, Live to watch or host, and Shop when you want to browse or sell.',
            '5. Use Gear for privacy, appearance, password, and devices. Policy and Terms are in this Help Center. If you need a person, open Help and message an admin.',
          ],
        ],
      ],
    ],
    'policy' => [
      'title' => 'Policy',
      'lead' => 'How Talsora collects, uses, and shares information. This is a product summary, not a substitute for independent legal advice.',
      'sections' => [
        ['What this policy covers', [
          'This Policy applies to personal, publisher, and commerce accounts on Talsora, including Home, Profile, messages, Live, Shop, and Help. Using the service means we process information needed to run those features. Account-level privacy choices live in Gear → Privacy after you sign in.',
        ]],
        ['Information we collect', [
          'We collect details you provide at sign-up and in Account tools, such as name, username, email, phone, birthday (for eligibility), bio, and profile media. We also collect content you post or send (posts, stories, reels, live, messages, shop listings, and reports) and activity needed to operate feeds, suggestions, safety tools, and payments when you shop.',
          'Device and session data (sign-in, linked accounts, and manage-devices records) help keep your account available and signed in only where you allow it.',
        ]],
        ['How we use it', [
          'We use this information to operate accounts, personalize Circle and Discover, deliver messages and notifications, run Live and Shop, prevent abuse, and respond when you contact Help. We may use automated systems to keep the product working and safer. We do not sell your name or email to advertisers. We may use activity and interests to make shop listings or promotions more relevant, and we may share aggregated reports that do not directly identify you unless you give permission.',
        ]],
        ['Sharing', [
          'Public content follows the audience you chose for that post or profile. People you message can see that conversation. Publisher and commerce accounts may show branded or selling content to a wider audience. We share information with service providers who host or process data for Talsora, and when the law requires it or we need to protect people or the product. Staff who handle Help can see what you send in a support conversation.',
        ]],
        ['Your choices', [
          'Signed-in people can change many visibility and notification settings in Gear → Privacy, Security, and related tools. You can deactivate or delete an account from Gear → Danger Zone. Deletion is not instant: content drops out of public view first, then we remove it from live systems and later from backups, except where law or safety investigations require a longer hold.',
        ]],
        ['Age and updates', [
          'Personal accounts must meet the minimum age shown at sign-up (currently 21). We may update this Policy when the product or the law changes. Continued use after an update means you accept the new Policy. Questions go to Help in this footer.',
        ]],
      ],
    ],
    'terms' => [
      'title' => 'Terms',
      'lead' => 'These Terms govern how you use Talsora. If you do not agree, do not use the service. This is a product summary, not a substitute for independent legal advice.',
      'sections' => [
        ['1. What Talsora is', [
          'Talsora provides personal, publisher, and commerce accounts so you can create, share, follow, message, go live, shop, and manage a public presence. We personalize feeds and suggestions from activity on the service, and we use tools (including automated systems) to keep the product working, safer, and available.',
          'We may store and process data on infrastructure needed to run the service. Related features such as Shop, Live, and publisher workspaces are part of the same service unless we say separate terms apply.',
        ]],
        ['2. How Talsora stays free to use', [
          'You can use Talsora without a subscription fee. We may show promotions, shop listings, or other paid placements. We do not sell your name or email to advertisers. We may use activity and interests to make those placements more relevant, and we may share aggregated reports that do not directly identify you unless you give permission.',
          'Publisher and commerce accounts may post branded or selling content. That content must be honest and follow these Terms.',
        ]],
        ['3. Policy', [
          'Using Talsora means we collect and use information needed to run accounts, feeds, messages, shop, and safety tools. You control many of those choices in Gear → Privacy after you sign in. Help from an admin is available through Help in this footer.',
        ]],
        ['4. Who can use Talsora', [
          'Personal accounts must meet the minimum age shown at sign-up (currently 21). You must not use the service if the law forbids it, if we have disabled your account for violations, or if you are using it to impersonate someone else. Provide accurate sign-up details and keep them up to date.',
        ]],
        ['5. How you may not use Talsora', [
          'Do not do anything unlawful, misleading, or fraudulent. Do not harass people, post illegal or sexually exploitative material, or help others break these Terms.',
          'Do not interfere with the product, abuse Help or report tools, scrape or collect data in an automated way without our permission, buy or sell accounts or login details, or post other people\'s private information or copyrighted work without rights.',
          'Do not reverse engineer the product, bypass access controls, or use a domain as a username without our written consent.',
        ]],
        ['6. Your posts and our license', [
          'You keep ownership of the content you post. To operate feeds, profiles, messages, live, shop, and related features, you grant Talsora a non-exclusive, worldwide license to host, display, distribute, and adapt that content according to your privacy settings. That license ends when the content is deleted from our systems, except where someone else still lawfully shares a copy or we must keep it for safety or legal reasons.',
          'You also allow us to show your username, photo, and public actions (such as follows or reactions) with content you engage with, and to install updates needed for the service to work.',
        ]],
        ['7. Our rights, content removal, and accounts', [
          'We may change a username that impersonates someone or infringes rights. Talsora keeps rights to its own names, marks, and built-in media. You may not use those marks except as we allow.',
          'We may remove content or limit, disable, or delete an account if we believe it violates these Terms, harms people, or is required by law. You can deactivate or delete from Gear → Danger Zone. Deletion is not instant: content drops out of public view first, then we remove it from live systems and later from backups, except where law or safety investigations require a longer hold.',
          'If we disable an account in error, contact Help and ask an admin to review it.',
        ]],
        ['8. Disputes, liability, and updates', [
          'The service is provided as available. We do not control what other people post or do. To the extent the law allows, we are not liable for lost posts, downtime, or indirect damages. If you have a dispute, start with Help so an admin can try to resolve it.',
          'Suggestions you send us may be used without payment or a duty to keep them secret.',
          'We may update these Terms when the product or the law changes. Continued use after an update means you accept the new Terms. If you do not agree, stop using Talsora and delete your account.',
        ]],
      ],
    ],
    'locations' => [
      'title' => 'Locations',
      'lead' => 'Where Talsora is available.',
      'sections' => [
        ['On the web', [
          'Talsora runs in your browser. Use the same account on any device where you sign in.',
        ]],
        ['More places', [
          'City and regional pages will appear here as they launch. Shop can also use a delivery location after you sign in.',
        ]],
      ],
    ],
    'shop' => [
      'title' => 'Shop',
      'lead' => 'Buy and sell on Talsora without leaving the product.',
      'sections' => [
        ['What Shop is', [
          'Shop is the storefront inside Talsora. Commerce accounts list products. Personal accounts browse, save favorites, set a delivery location, and check out. Publisher pages can still exist alongside Shop — selling happens on a commerce account.',
        ]],
        ['How a new shopper uses it', [
          'Sign in with a personal account, open Shop, and scan listings. Save items you may want later. Add a delivery address when you are ready to check out. Order help lives in Shop after you sign in, and you can always message an admin from Help.',
        ]],
        ['How a new seller uses it', [
          'Create a commerce account. Brand names may need admin approval at sign-up. After you are in, list products, keep inventory and orders in the commerce tools, and watch messages from buyers. Keep listings honest — they must follow Terms.',
        ]],
      ],
    ],
    'popular' => [
      'title' => 'Popular',
      'lead' => 'Discover people, publishers, and posts you do not follow yet.',
      'sections' => [
        ['What Popular is', [
          'Popular is the Discover feed on Home: new posts, publisher pages, and suggestions people are finding right now. Circle stays focused on friends and follows. Discover is how a new user fills an empty feed.',
        ]],
        ['How a new user uses it', [
          'Sign in, open Home, then switch to Discover. Follow people and publisher pages that fit you. Those follows start showing up in Circle. You can also open a profile from a post and follow from there.',
          'Clips and public Live sit nearby when you want video instead of a scrolling feed. Suggestions update as you watch, follow, and hide what you do not want.',
        ]],
      ],
    ],
    'live' => [
      'title' => 'Live',
      'lead' => 'Watch real-time video or go live yourself.',
      'sections' => [
        ['What Live is', [
          'Live is real-time video on Talsora. Public Live is the open door: join lives that are on now without opening a specific profile first. Live Studio is where a signed-in host starts and runs a live.',
        ]],
        ['How a new viewer uses it', [
          'Sign in, open Live (or Public Live), and pick a room that is on. Watch, react, and leave when you are done. You do not have to go live to watch.',
        ]],
        ['How a new host uses it', [
          'Sign in, open Live Studio, and start a live when you are ready. Friends and followers can join; public lives can also show in Public Live. End the live from Studio when you are finished. Clips stay available for short videos that are not live.',
        ]],
      ],
    ],
  ];

  echo '<div class="help-center auth-legal is-nav-closed" id="authLegal" ' . ($activeTab === '' ? 'hidden' : '') . '>';
  echo '<header class="hc-top">';
  echo '<div class="hc-brand-row">';
  echo '<a class="hc-brand js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href('about', $addingAccount)) . '" data-index-tab="about">';
  echo '<span class="auth-brand-orb" aria-hidden="true"><span class="auth-brand-mark">t</span></span>';
  echo '<span class="hc-brand-text"><b>Talsora</b></span></a>';
  echo '<button type="button" class="hc-menu" id="hcNavToggle" aria-controls="hcNav" aria-expanded="false" aria-label="Open navigation" title="Menu">';
  echo '<span class="hc-menu-ico" aria-hidden="true"></span>';
  echo '<span class="sr-only">Menu</span></button>';
  echo '</div>';
  echo '<label class="hc-search"><span class="sr-only">Search help articles</span>';
  echo '<input type="search" id="hcSearch" placeholder="Search help articles..." autocomplete="off"></label>';
  echo '<span class="hc-lang">English</span>';
  if ($loggedIn) {
    echo '<a class="hc-home-cta" href="home.php?tab=for-you">Home</a>';
  } else {
    $loginHref = $addingAccount ? 'index.php?add_account=1' : 'index.php';
    echo '<a class="hc-login js-hc-login" href="' . index_footer_tab_h($loginHref) . '">Log in</a>';
  }
  echo '</header>';
  echo '<div class="hc-body">';
  index_render_help_center_nav($activeTab, $addingAccount);
  echo '<div class="hc-main"' . index_help_story_inline_style() . '>';
  echo '<p class="hc-crumb" id="hcCrumb">' . index_footer_tab_h($activeTab !== '' ? index_help_crumb_text($activeTab) : 'Talsora') . '</p>';
  foreach ($panels as $key => $panel) {
    if ($key === 'about') {
      index_render_about_story($panel, $activeTab, $loggedIn, $addingAccount);
      continue;
    }
    $show = $activeTab === $key ? '' : ' hidden';
    echo '<article class="auth-legal-article" data-legal-panel="' . index_footer_tab_h($key) . '"' . $show . '>';
    echo '<div class="hc-title-row"><h1>' . index_footer_tab_h((string)$panel['title']) . '</h1><button type="button" class="hc-copy js-hc-copy">Copy link</button></div>';
    echo '<p class="auth-legal-lead">' . index_footer_tab_h((string)$panel['lead']) . '</p>';
    $hero = (array)($panel['hero'] ?? []);
    if ($hero) {
      echo '<div class="hc-about-hero">';
      foreach ($hero as $shot) {
        $src = trim((string)($shot['src'] ?? ''));
        if ($src === '') {
          continue;
        }
        echo '<img src="' . index_footer_tab_h($src) . '" alt="' . index_footer_tab_h((string)($shot['alt'] ?? '')) . '" loading="lazy">';
      }
      echo '</div>';
    }
    $secI = 0;
    foreach ((array)($panel['sections'] ?? []) as $section) {
      $secI++;
      $secId = 'hc-' . $key . '-' . $secI;
      $heading = '';
      $paras = [];
      $image = '';
      $alt = '';
      if (isset($section['heading']) || isset($section['paras'])) {
        $heading = (string)($section['heading'] ?? '');
        $paras = (array)($section['paras'] ?? []);
        $image = trim((string)($section['image'] ?? ''));
        $alt = (string)($section['alt'] ?? '');
      } else {
        $heading = (string)($section[0] ?? '');
        $paras = (array)($section[1] ?? []);
      }
      echo '<h2 id="' . index_footer_tab_h($secId) . '">' . index_footer_tab_h($heading) . '</h2>';
      if ($image !== '') {
        echo '<figure class="hc-about-figure"><img src="' . index_footer_tab_h($image) . '" alt="' . index_footer_tab_h($alt) . '" loading="lazy"></figure>';
      }
      foreach ($paras as $para) {
        echo '<p>' . index_footer_tab_h((string)$para) . '</p>';
      }
      $list = (array)($section['list'] ?? []);
      if ($list) {
        echo '<ul>';
        foreach ($list as $item) {
          echo '<li>' . index_footer_tab_h((string)$item) . '</li>';
        }
        echo '</ul>';
      }
    }
    if ($key === 'guidance') {
      echo '<p><a class="js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href('help', $addingAccount)) . '" data-index-tab="help">Message an admin in Help</a></p>';
    }
    if ($key === 'shop' && $loggedIn) {
      echo '<p><a href="shop.php">Open Shop</a></p>';
    }
    if ($key === 'popular' && $loggedIn) {
      echo '<p><a href="home.php?tab=discover">Open Discover</a></p>';
    }
    if ($key === 'live' && $loggedIn) {
      echo '<p><a href="public_live.php">Open Live</a></p>';
    }
    echo '</article>';
  }

  foreach (index_feature_articles() as $key => $article) {
    $show = $activeTab === $key ? '' : ' hidden';
    echo '<article class="auth-legal-article" data-legal-panel="' . index_footer_tab_h($key) . '"' . $show . '>';
    echo '<div class="hc-title-row"><h1>' . index_footer_tab_h((string)$article['title']) . '</h1><button type="button" class="hc-copy js-hc-copy">Copy link</button></div>';
    echo '<p class="auth-legal-lead">' . index_footer_tab_h((string)$article['lead']) . '</p>';
    $guides = index_help_guides_for_article($article);
    if ($guides) {
      if (count($guides) > 1) {
        echo '<div class="hc-pills" role="navigation" aria-label="Related questions">';
        $gi = 0;
        foreach ($guides as $guide) {
          $gi++;
          echo '<a class="hc-pill" href="#hc-' . index_footer_tab_h($key) . '-g' . $gi . '">' . index_footer_tab_h((string)($guide['title'] ?? '')) . '</a>';
        }
        echo '</div>';
      }
      echo '<div class="hc-accordion">';
      $gi = 0;
      foreach ($guides as $guide) {
        $gi++;
        $gid = 'hc-' . $key . '-g' . $gi;
        $openHref = index_footer_tab_href($key, $addingAccount) . '#' . $gid;
        echo '<details class="hc-acc" id="' . index_footer_tab_h($gid) . '">';
        echo '<summary>' . index_footer_tab_h((string)($guide['title'] ?? '')) . '</summary>';
        echo '<div class="hc-acc-body">';
        echo '<div class="hc-acc-tools">';
        echo '<a class="hc-acc-tool" href="' . index_footer_tab_h($openHref) . '">' . index_help_open_icon() . 'Open article</a>';
        echo '<button type="button" class="hc-acc-tool js-hc-copy-hash" data-hash="' . index_footer_tab_h($gid) . '">' . index_help_copy_icon() . '<span class="hc-acc-copy-label">Copy link</span></button>';
        echo '</div>';
        $heading = trim((string)($guide['heading'] ?? ''));
        if ($heading !== '') {
          echo '<h2 class="hc-acc-heading">' . index_footer_tab_h($heading) . '</h2>';
        }
        $steps = (array)($guide['steps'] ?? []);
        if ($steps) {
          echo '<ol class="hc-acc-steps">';
          foreach ($steps as $step) {
            echo '<li>' . index_help_step_html((string)$step) . '</li>';
          }
          echo '</ol>';
        }
        $note = trim((string)($guide['note'] ?? ''));
        if ($note !== '') {
          echo '<p class="hc-acc-note">' . index_help_linked_text($note, (array)($guide['links'] ?? []), $addingAccount) . '</p>';
        }
        echo '</div></details>';
      }
      echo '</div>';
    }
    echo '<div class="hc-pills hc-pills-more">';
    echo '<a class="hc-pill js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href('help', $addingAccount)) . '" data-index-tab="help">I have a different question</a>';
    echo '</div>';
    index_help_render_helpful($key);
    index_help_render_related(index_help_parent_topic($key), $addingAccount, $key);
    echo '</article>';
  }

  foreach (index_help_each_topic() as $topic) {
    index_help_render_topic_landing($topic, $activeTab, $addingAccount);
  }

  $helpShow = $activeTab === 'help' ? '' : ' hidden';
  echo '<article class="auth-legal-article" data-legal-panel="help"' . $helpShow . '>';
  echo '<div class="hc-title-row"><h1>How can we help you?</h1><button type="button" class="hc-copy js-hc-copy">Copy link</button></div>';
  echo '<p class="auth-legal-lead">Search the left, open a topic, or message an admin. Featured topics below are the usual starting points.</p>';
  echo '<h2 class="hc-acc-heading">Featured topics</h2>';
  echo '<div class="hc-featured">';
  $featured = [
    ['tab' => 'login-cant', 'label' => 'Check your account status', 'text' => 'Can\'t log in, deactivated, or the wrong account type.'],
    ['tab' => 'topic-reels', 'label' => 'Clips', 'text' => 'Create, manage, and share short video.'],
    ['tab' => 'topic-edits', 'label' => 'Edits', 'text' => 'Trim, crop, cover, and drafts.'],
    ['tab' => 'signup-types', 'label' => 'Publisher and commerce', 'text' => 'Public pages and Shop brands.'],
    ['tab' => 'topic-publisher', 'label' => 'Publisher', 'text' => 'Create a public page and get found on Discover.'],
    ['tab' => 'topic-seller', 'label' => 'Seller', 'text' => 'List products, take orders, and get paid.'],
    ['tab' => 'topic-shop-feat', 'label' => 'Shop', 'text' => 'Listings, orders, and purchase protection.'],
    ['tab' => 'help', 'label' => 'Message an admin', 'text' => 'Account, posts, shop, or anything else.'],
  ];
  foreach ($featured as $card) {
    $ft = (string)$card['tab'];
    echo '<a class="hc-featured-card js-index-tab" href="' . index_footer_tab_h(index_footer_tab_href($ft, $addingAccount)) . '" data-index-tab="' . index_footer_tab_h($ft) . '">';
    echo '<strong>' . index_footer_tab_h((string)$card['label']) . '</strong>';
    echo '<span>' . index_footer_tab_h((string)$card['text']) . '</span></a>';
  }
  echo '</div>';
  echo '<h2 class="hc-acc-heading">Message Help</h2>';
  echo '<p class="auth-legal-lead">Replies show in this same thread.</p>';
  if ($loggedIn) {
    echo '<div class="auth-help-chat" id="indexHelpRoot" data-endpoint="ajax/admin_support_chat.php">';
    echo '<div class="auth-help-head">Admin support chat</div>';
    echo '<div class="auth-help-thread" id="indexHelpThread" aria-live="polite"></div>';
    echo '<div class="auth-help-compose"><div class="auth-help-compose-row">';
    echo '<textarea class="auth-help-input" id="indexHelpInput" rows="2" placeholder="Describe what you need help with…"></textarea>';
    echo '<button type="button" class="auth-help-send" id="indexHelpSend">Send</button>';
    echo '</div><p class="auth-help-err" id="indexHelpErr" hidden></p></div></div>';
  } else {
    echo '<p class="auth-help-signin">Sign in to message an admin. Click <strong>Log in</strong> at the top right, then open Help again.</p>';
  }
  echo '</article>';
  echo '</div></div></div>';
}
