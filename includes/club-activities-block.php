<?php
declare(strict_types=1);
/** @var array $activities */
/** @var int $clubId */
?>
<div class="club-activities-list">
    <?php if (!$activities): ?>
        <div class="club-card-block">
            <p class="muted">В клубе пока нет публичных активностей.</p>
        </div>
    <?php else: ?>
        <?php foreach ($activities as $a): ?>
            <?php
                $aid = (int)$a['id'];
                $hasTrack = !empty($a['map_points']);
                $activityDate = $a['started_at'] ?: $a['created_at'];
                $isRide = $a['type'] === 'ride';
            ?>
            <article class="club-activity-card" data-activity-id="<?= $aid ?>">
                <div class="club-activity-card__head">
                    <a class="club-activity-card__user"
                       href="<?= e(url('profile.php?u=' . urlencode((string)$a['username']))) ?>">
                        <span class="avatar avatar--sm">
                            <?php if (!empty($a['avatar_url'])): ?>
                                <img src="<?= e($a['avatar_url']) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_substr((string)$a['display_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </span>
                        <span class="club-activity-card__user-info">
                            <span class="club-activity-card__user-name"><?= e($a['display_name']) ?></span>
                            <span class="club-activity-card__user-meta muted">
                                @<?= e($a['username']) ?>
                                · <?= e(date('d.m.Y H:i', strtotime((string)$activityDate))) ?>
                            </span>
                        </span>
                    </a>
                    <span class="activity-type">
                        <?= e(club_activity_icon((string)$a['type'])) ?>
                        <?= e(club_activity_label((string)$a['type'])) ?>
                    </span>
                </div>

                <?php if ($hasTrack): ?>
                    <a class="club-activity-card__map-link"
                       href="<?= e(url('activity.php?id=' . $aid)) ?>">
                        <div class="club-activity-map"
                             data-activity-id="<?= $aid ?>"
                             data-points="<?= e(json_encode($a['map_points'], JSON_UNESCAPED_UNICODE)) ?>"
                             data-initialized="0"></div>
                    </a>
                <?php endif; ?>

                <a class="club-activity-card__title-link"
                   href="<?= e(url('activity.php?id=' . $aid)) ?>">
                    <h3 class="club-activity-card__title"><?= e($a['title']) ?></h3>
                </a>

                <?php if (!empty($a['description'])): ?>
                    <div class="club-activity-card__desc muted">
                        <?= e(mb_substr((string)$a['description'], 0, 160)) ?><?= mb_strlen((string)$a['description']) > 160 ? '…' : '' ?>
                    </div>
                <?php endif; ?>

                <div class="club-activity-card__stats">
                    <div class="club-act-stat">
                        <span class="club-act-stat__value"><?= e(format_distance((float)$a['distance_m'])) ?></span>
                        <span class="club-act-stat__label">Дистанция</span>
                    </div>
                    <div class="club-act-stat">
                        <span class="club-act-stat__value"><?= e(format_duration((int)$a['duration_sec'])) ?></span>
                        <span class="club-act-stat__label">Время</span>
                    </div>
                    <div class="club-act-stat">
                        <span class="club-act-stat__value">
                            <?php if ($isRide && $a['avg_speed_mps']): ?>
                                <?= number_format((float)$a['avg_speed_mps'] * 3.6, 1, '.', '') ?> км/ч
                            <?php else: ?>
                                <?= e(format_pace((float)$a['distance_m'], (int)$a['duration_sec'])) ?>
                            <?php endif; ?>
                        </span>
                        <span class="club-act-stat__label"><?= $isRide ? 'Средняя' : 'Темп' ?></span>
                    </div>
                    <div class="club-act-stat">
                        <span class="club-act-stat__value">
                            <?= $a['elevation_gain_m'] ? (int)$a['elevation_gain_m'] . ' м' : '—' ?>
                        </span>
                        <span class="club-act-stat__label">Набор</span>
                    </div>
                </div>

                <?php if (!empty($a['avg_hr']) || !empty($a['avg_power_w']) || !empty($a['avg_cadence'])): ?>
                    <div class="club-activity-card__sensors">
                        <?php if (!empty($a['avg_hr'])): ?>
                            <span>❤️ <?= (int)$a['avg_hr'] ?> <small>уд/мин</small></span>
                        <?php endif; ?>
                        <?php if (!empty($a['avg_power_w'])): ?>
                            <span>⚡ <?= (int)$a['avg_power_w'] ?> <small>Вт</small></span>
                        <?php endif; ?>
                        <?php if (!empty($a['avg_cadence'])): ?>
                            <span>🔄 <?= (int)$a['avg_cadence'] ?> <small>об/мин</small></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="club-activity-card__footer">
                    <a class="action" href="<?= e(url('activity.php?id=' . $aid)) ?>">
                        <span class="action__icon">♥</span>
                        <span><?= (int)$a['likes_count'] ?></span>
                    </a>
                    <a class="action" href="<?= e(url('activity.php?id=' . $aid . '#comments')) ?>">
                        <span class="action__icon">💬</span>
                        <span><?= (int)$a['comments_count'] ?></span>
                    </a>
                    <?php if ((int)$a['photos_count'] > 0): ?>
                        <span class="action action--static">
                            <span class="action__icon">🖼</span>
                            <span><?= (int)$a['photos_count'] ?></span>
                        </span>
                    <?php endif; ?>
                    <a class="action club-activity-card__open"
                       href="<?= e(url('activity.php?id=' . $aid)) ?>">
                        <span class="action__icon">🔗</span>
                        <span>Открыть</span>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>