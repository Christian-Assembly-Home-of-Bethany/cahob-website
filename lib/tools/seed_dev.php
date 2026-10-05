<?php
// Fills the local database with sample messages so the Messages pages can be tried out.
// It replaces every message in that database. Local only: it refuses to run unless the
// config has 'debug' => true, which the live config never does.
//
// Usage: make seed

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

if (empty(config()['debug'])) {
    fwrite(STDERR, "Refusing to seed: this only runs against a local config with 'debug' => true.\n");
    exit(1);
}

function days_ago(int $days): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
}

$samples = [
    [
        'slug' => 'no-longer-i-but-christ',
        'title_en' => 'No Longer I, but Christ',
        'title_zh' => '不再是我，乃是基督',
        'body_en' => <<<'HTML'
            <p style="text-align: center;"><strong>Scripture Reading: Galatians 2:20</strong></p>
            <blockquote><p>“I am crucified with Christ; and it is no longer I who live, but Christ lives in me; and the life which I now live in the flesh I live in faith, the faith of the Son of God, who loved me and gave Himself up for me.”</p></blockquote>
            <p>This verse is not a motto to repeat but a testimony to live. Paul does not say that he tried harder and became a better man. He says that the old <strong>I</strong> was crucified, and that <em>another life</em> now lives in him.</p>
            <h2>1. Crucified with Christ</h2>
            <p>God’s way is not to improve us but to replace us. When Christ died, we died with Him. This is a fact accomplished on the cross, and it becomes our experience as we turn from ourselves to the Lord.</p>
            <h2>2. Christ Lives in Me</h2>
            <blockquote>“I am the vine; you are the branches.</blockquote>
            <blockquote>He who abides in Me and I in him, he bears much fruit; for apart from Me you can do nothing.” (John 15:5)</blockquote>
            <p>The same Christ who died for us now lives in us. Day by day He wants to:</p>
            <ol>
            <li>be our life in our daily walk;</li>
            <li>be our patience when we have none;</li>
            <li>be our love toward one another in the church.</li>
            </ol>
            <hr>
            <h3>For Our Prayer This Week</h3>
            <ul>
            <li>Lord, I give You the ground to live in me.</li>
            <li>Lord, make our fellowship a testimony of You.</li>
            </ul>
            <p>Read more about our life together on the <a href="/who-we-are.html">Who We Are</a> page.</p>
            HTML,
        'body_zh' => <<<'HTML'
            <p style="text-align: center;"><strong>讀經：加拉太書二章20節</strong></p>
            <blockquote><p>「我已經與基督同釘十字架，現在活著的不再是我，乃是基督在我裡面活著；並且我如今在肉身活著，是因信神的兒子而活，祂是愛我，為我捨己。」</p></blockquote>
            <p>這節經文不是一句口號，而是一個生活的見證。保羅並不是說他更加努力，成了一個更好的人；他乃是說舊的<strong>我</strong>已經釘了十字架，如今是<em>另一個生命</em>在他裡面活著。</p>
            <h2>一、與基督同釘十字架</h2>
            <p>神的路不是改良我們，乃是頂替我們。基督死的時候，我們也與祂同死。這是在十字架上已經完成的事實，當我們從自己轉向主時，就成了我們的經歷。</p>
            <h2>二、基督在我裡面活著</h2>
            <blockquote>「我是葡萄樹，你們是枝子。</blockquote>
            <blockquote>住在我裡面的，我也住在他裡面，這人就多結果子；因為離了我，你們就不能作甚麼。」（約翰福音十五章5節）</blockquote>
            <p>那為我們死的基督，如今活在我們裡面。祂天天渴望：</p>
            <ol>
            <li>在日常生活中作我們的生命；</li>
            <li>在我們沒有忍耐時作我們的忍耐；</li>
            <li>在教會中作我們彼此相愛的愛。</li>
            </ol>
            <hr>
            <h3>本週禱告</h3>
            <ul>
            <li>主啊，我把地位給你，讓你在我裡面活著。</li>
            <li>主啊，使我們的交通成為你的見證。</li>
            </ul>
            <p>更多關於我們的生活，請看<a href="/who-we-are-zh.html">我們是誰</a>。</p>
            HTML,
        'days_ago' => 1,
    ],
    [
        // Like an imported Blogger post: no titles, the body opens with the pastor's own heading.
        'slug' => '__date__',
        'title_en' => '',
        'title_zh' => '',
        'body_en' => <<<'HTML'
            <p style="text-align: center;"><strong>Abiding in the Vine</strong></p>
            <p style="text-align: center;">Scripture Reading: John 15:4–5</p>
            <p>The Lord did not ask the branches to work hard to produce fruit. He asked them to abide. Fruit is the issue of the life of the vine flowing through the branches.</p>
            <p>To abide is to remain in fellowship with Him, to keep the channel open, and to let His life do what we cannot do.</p>
            HTML,
        'body_zh' => <<<'HTML'
            <p style="text-align: center;"><strong>住在葡萄樹上</strong></p>
            <p style="text-align: center;">讀經：約翰福音十五章4–5節</p>
            <p>主並沒有要枝子努力結果子，祂乃是要枝子住在祂裡面。果子乃是葡萄樹的生命流經枝子的結果。</p>
            <p>住在主裡面，就是留在與祂的交通中，保持通道暢通，讓祂的生命作我們所不能作的。</p>
            HTML,
        'days_ago' => 4,
    ],
    [
        'slug' => 'bible-reading-notes-abide-in-him',
        'title_en' => '',
        'title_zh' => '讀經隨筆：住在主裡面',
        'body_en' => '',
        'body_zh' => <<<'HTML'
            <p>約翰一書二章二十八節說：「小子們哪，你們要住在主裡面。」這是一個簡單卻深刻的吩咐。</p>
            <ul>
            <li>住在主裡面，是一種內裡的交通。</li>
            <li>住在主裡面，使我們在祂來的時候可以坦然無懼。</li>
            </ul>
            <p>願我們每天早晨都花時間在主面前，讓祂的話住在我們裡面。</p>
            HTML,
        'days_ago' => 8,
    ],
    [
        'slug' => 'a-reading-schedule-for-romans',
        'title_en' => 'A Reading Schedule for Romans',
        'title_zh' => '',
        'body_en' => <<<'HTML'
            <p>Over the next four weeks we will read through Romans together. Here is the plan:</p>
            <table>
            <thead><tr><th>Week</th><th>Chapters</th><th>Focus</th></tr></thead>
            <tbody>
            <tr><td>1</td><td>1–4</td><td>The gospel of God and justification by faith</td></tr>
            <tr><td>2</td><td>5–8</td><td>Life in Christ and the law of the Spirit of life</td></tr>
            <tr><td>3</td><td>9–11</td><td>God’s selection and Israel</td></tr>
            <tr><td>4</td><td>12–16</td><td>The Body of Christ and the church life</td></tr>
            </tbody>
            </table>
            <p>Please bring your Bible and notes on Friday evenings.</p>
            HTML,
        'body_zh' => '',
        'days_ago' => 12,
    ],
];

$fillers = [
    ["The Lord's Table", '主的桌子', '1 Corinthians 10:16–17', '哥林多前書十章16–17節'],
    ['Morning Watch', '晨更', 'Psalm 5:3', '詩篇五篇3節'],
    ['Walking by the Spirit', '憑著靈而行', 'Galatians 5:16, 25', '加拉太書五章16、25節'],
    ['The Body of Christ', '基督的身體', 'Ephesians 4:15–16', '以弗所書四章15–16節'],
    ['Christ Our Life', '基督是我們的生命', 'Colossians 3:4', '歌羅西書三章4節'],
    ['Prayer and Fellowship', '禱告與交通', 'Acts 2:42', '使徒行傳二章42節'],
    ['The Word Became Flesh', '話成了肉體', 'John 1:14', '約翰福音一章14節'],
    ['Grace upon Grace', '恩上加恩', 'John 1:16', '約翰福音一章16節'],
    ['Renewed Day by Day', '日日更新', '2 Corinthians 4:16', '哥林多後書四章16節'],
    ['The Good Shepherd', '好牧人', 'John 10:11', '約翰福音十章11節'],
    ['Seek First His Kingdom', '先求祂的國', 'Matthew 6:33', '馬太福音六章33節'],
    ['A Living Hope', '活潑的盼望', '1 Peter 1:3', '彼得前書一章3節'],
    ['Rejoice in the Lord Always', '要常常在主裡喜樂', 'Philippians 4:4', '腓立比書四章4節'],
    ['The Light of the World', '世上的光', 'John 8:12', '約翰福音八章12節'],
    ['Love One Another', '彼此相愛', 'John 13:34', '約翰福音十三章34節'],
    ['Rooted and Grounded in Love', '在愛中生根立基', 'Ephesians 3:17', '以弗所書三章17節'],
    ['Looking unto Jesus', '仰望耶穌', 'Hebrews 12:2', '希伯來書十二章2節'],
    ['Built Together', '被建造在一起', 'Ephesians 2:22', '以弗所書二章22節'],
    ['Hidden with Christ in God', '與基督一同藏在神裡面', 'Colossians 3:3', '歌羅西書三章3節'],
];
foreach ($fillers as $i => [$en, $zh, $refEn, $refZh]) {
    $samples[] = [
        'slug' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($en)), '-'),
        'title_en' => $en,
        'title_zh' => $zh,
        'body_en' => "<p>Sample message for trying out the Messages pages. Scripture reading: $refEn.</p>"
            . '<p>Brothers and sisters, the Lord desires to be our life and our living. As we come to His word together, may we not only understand it but enjoy Christ day by day and grow up into Him in all things.</p>',
        'body_zh' => "<p>這是用來在本機試用信息頁面的範例內容。讀經：{$refZh}。</p>"
            . '<p>弟兄姊妹，主渴望作我們的生命和生活。當我們一同來到祂的話語面前，願我們不僅明白，更是天天享受基督，在一切事上長到祂裡面。</p>',
        'days_ago' => 15 + 7 * $i,
    ];
}

$pdo = db();
$pdo->beginTransaction();
$pdo->exec('DELETE FROM messages');
$insert = $pdo->prepare(
    'INSERT INTO messages (slug, title_en, body_en, title_zh, body_zh, status, published_at)
     VALUES (:slug, :title_en, :body_en, :title_zh, :body_zh, :status, :published_at)'
);
foreach ($samples as $sample) {
    $publishedAt = days_ago($sample['days_ago']);
    $insert->execute([
        // Untitled posts get their date as a slug, the same rule the editor will use.
        'slug' => $sample['slug'] === '__date__' ? substr($publishedAt, 0, 10) : $sample['slug'],
        'title_en' => $sample['title_en'],
        'body_en' => $sample['body_en'],
        'title_zh' => $sample['title_zh'],
        'body_zh' => $sample['body_zh'],
        'status' => 'published',
        'published_at' => $publishedAt,
    ]);
}
// A draft, which must never appear on the public pages.
$insert->execute([
    'slug' => 'unfinished-draft',
    'title_en' => 'Unfinished draft',
    'body_en' => '<p>This draft should not appear on the public pages.</p>',
    'title_zh' => '',
    'body_zh' => '',
    'status' => 'draft',
    'published_at' => days_ago(0),
]);
$pdo->commit();

printf("Seeded %d published messages and 1 draft.\n", count($samples));
