<?php
if ($type === 'image'): ?>
    <img src="<?= htmlspecialchars($attachment) ?>" class="media-image">
<?php elseif ($type === 'video'): ?>
    <video controls class="media-video">
        <source src="<?= htmlspecialchars($attachment) ?>">
    </video>
<?php elseif ($type === 'pdf'): ?>
    <iframe src="<?= htmlspecialchars($attachment) ?>" class="media-pdf"></iframe>
<?php else: ?>
    <a href="<?= htmlspecialchars($attachment) ?>" class="media-file" target="_blank">Download File</a>
<?php endif; ?>