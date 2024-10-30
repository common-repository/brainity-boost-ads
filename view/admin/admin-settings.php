<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

global $wpdb;

$table_name = $wpdb->prefix . 'brainity';

$config = $wpdb->get_results("SELECT * FROM  $table_name ");
?>
<?php if (class_exists('WooCommerce')) { ?>
    <?php if (!$config[0]->is_logged_in) { ?>
        <div id="app" class="app-wrapper table">

            <div class="text-center center-row">
                <a class="navbar-brand" href="#">
                    <img width="250" src="<?php echo(WPBRAINITY_ASSETS_URL . 'img/brainity-logo.png') ?>"
                         alt="Brainity">
                </a>
                <div class="box box-sm">
                    <div class="box-content">
                        <div class="overlay">
                            <div class="overlay-content">
                                <div class="loader"></div>
                            </div>
                        </div>
                        <header class="box-header">
                            <h5 class="box-title">Welcome Aboard!</h5>
                        </header>
                        <div class="box-body">
                            <p>Let’s connect Brainity as your business page advertiser.</p>
                            <p>
                                <a class="brainity-facebook-login" href="#">
                                    <img style="max-width:200px;"
                                         src="<?php echo(WPBRAINITY_ASSETS_URL . 'img/facebook.png') ?>"
                                         alt="Continue with Facebook"/>
                                </a>
                            </p>
                        </div>
                        <footer class="box-footer">
                            <p><a href="https://help.brainity.co/first-steps-with-brainity/account-configuration">I
                                    can’t connect with my Business Manager.</a></p>
                        </footer>
                    </div>
                </div>
            </div>

        </div>
    <?php } else { ?>
        <nav class="navbar navbar-light bg-white navbar-expand-md">
            <a href="#" target="_self" class="navbar-brand">
                <a href="#" class="navbar-brand">
                    <img width="110" height="30" src="<?php echo(WPBRAINITY_ASSETS_URL . 'img/brainity-logo.png') ?>"
                         alt="Brainity">
                </a>
            </a>
            <button type="button" aria-label="Toggle navigation" aria-controls="nav_collapse" aria-expanded="false"
                    class="navbar-toggler">
                <span class="navbar-toggler-icon"></span></button>
            <div id="nav_collapse" class="navbar-collapse collapse" style="display: none;">
                <ul class="navbar-nav">
                    <li class="nav-item only-icon">
                        <a rel="noopener" target="_blank" href="https://help.brainity.co" class="nav-link">
                            <svg class="icon-ic-support">
                                <use xlink:href="<?php echo(WPBRAINITY_ASSETS_URL . 'img/icons/icons.svg#icon-ic-support') ?>"></use>
                            </svg>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>


        <div class="app-wrapper">
            <div class="app-content">
                <div class="main">
                    <div class="row">
                        <div class="col-md-6 box">
                            <div class="box-content">
                                <header class="box-header">
                                    <h5 class="box-title">Access To Manager</h5>
                                </header>
                                <div class="box-body">
                                    <p>Configure Your Campaigns</p>
                                </div>
                                <footer class="modal-footer open-manager">
                                    <a href="<?php echo(admin_url('admin.php?page=brainity-go-to-manager')) ?>"
                                       target="_blank" class="btn btn-primary">
                                        Open Manager
                                    </a>
                                </footer>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>
<?php } else { ?>
    <div class="wrap">
        <h2>Activate WooCommerce</h2>
        <div>
            <br>
            <p>You must first activate <strong>WooCommerce</strong></p>
        </div>
    </div>
<?php } ?>
