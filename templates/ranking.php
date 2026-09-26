<?php
/**
 * Template for Init View Ranking
 *
 * Có thể override bằng cách copy file này vào {theme}/init-view-count/ranking.php.
 *
 * @package Init_View_Count
 *
 * @var array $atts
 * @var array $tabs
 * @var array $labels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="init-plugin-suite-view-count-ranking <?php echo esc_attr( $atts['class'] ); ?>" data-number="<?php echo (int) $atts['number']; ?>" data-post-type="<?php echo esc_attr( $atts['post_type'] ); ?>">
	<ul class="init-plugin-suite-view-count-ranking-tabs">
		<?php
		foreach ( $tabs as $i => $key ) :
			if ( ! isset( $labels[ $key ] ) ) {
				continue;
			}
			?>
			<li<?php echo ( 0 === $i ) ? ' class="active"' : ''; ?>>
				<button type="button" data-range="<?php echo esc_attr( $key ); ?>">
					<?php echo esc_html( $labels[ $key ] ); ?>
				</button>
			</li>
		<?php endforeach; ?>
	</ul>
	<div class="init-plugin-suite-view-count-ranking-panels">
		<?php
		foreach ( $tabs as $i => $key ) :
			if ( ! isset( $labels[ $key ] ) ) {
				continue;
			}
			?>
			<div class="init-plugin-suite-view-count-ranking-panel"<?php echo ( 0 !== $i ) ? ' hidden' : ''; ?>>
				<div class="init-plugin-suite-view-count-ranking-content" data-range="<?php echo esc_attr( $key ); ?>" data-loaded="false">
					<?php
					if ( 0 === $i ) :
						for ( $j = 0; $j < (int) $atts['number']; $j++ ) :
							?>
							<div class="init-plugin-suite-view-count-ranking-skeleton-item">
								<div class="init-plugin-suite-view-count-ranking-skeleton-thumb"></div>
								<div class="init-plugin-suite-view-count-ranking-skeleton-text">
									<div class="init-plugin-suite-view-count-ranking-skeleton-title"></div>
									<div class="init-plugin-suite-view-count-ranking-skeleton-line"></div>
								</div>
							</div>
							<?php
						endfor;
					endif;
					?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
