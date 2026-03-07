<!DOCTYPE html>
<html>
	<head>
		<title>photos du potagerffp3</title>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
				<link href="//cdn.jsdelivr.net/npm/featherlight@1.7.14/release/featherlight.min.css" type="text/css" rel="stylesheet" />

		<link rel="stylesheet" href="https://iot.olution.info/assets/css/main.css" />
		<noscript><link rel="stylesheet" href="https://iot.olution.info/assets/css/noscript.css" /></noscript>
		<link rel="shortcut icon" type="image/png" href="/images/favico.png"/>
	</head>
	<body class="is-preload">
		 <!-- Wrapper -->
			<div id="wrapper" class="fade-in">
				 <!-- Header -->
					<header id="header">
						<a href="https://iot.olution.info/index.php" class="logo">olution iot datas</a>
					</header>
				 <!-- Nav -->
					<nav id="nav">
						<ul class="links">
							<li><a href="https://iot.olution.info/index.php">olution</a></li>
							<li class="active"><a href="https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php">le prototype farmflow 3</a></li>
							<li><a href="https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php">phasmopolis</a></li>
							<li><a href="https://iot.olution.info/msp1/msp1datas/msp1-data.php">le tiny garden</a></li>
							
						</ul>
						<ul class="icons">
							<li><a href="https://olution.info/course/view.php?id=511" class="icon solid fa-leaf"><span class="label">olution</span></a></li>
							<li><a href="https://farmflow.marout.org/" class="icon solid fa-fish"><span class="label">farmflow</span></a></li>
						</ul>
					</nav>
				 <!-- Main -->
					<div id="main">
						 <!-- Featured Post -->
							<article class="post featured">
								<header class="major">
									<h2>Les photos</h2>
									<p>Le système est pris en photo par une caméra simple, basée sur une carte développement ESP32. Les résultats sont publiés régulièrement.</p>
								</header>
								<!-- <section class="posts"> -->
						            <div class="box alt">
										<div class="row gtr-50 gtr-uniform">
                                    <?php
                                      // Image extensions
                                      $image_extensions = array("png","jpg","jpeg","gif");
                                      // Target directory
                                      $dir = 'ffp3photos/';
                                      if (is_dir($dir)){
                                        $files = glob('$dir/*.jpg');
                                        //$count = 1;
                                        $files = scandir($dir);
                                        $files_count = count($files);
                                        $per_page = 12;
                                        $max_pages = ceil($files_count / $per_page);
                                        $files = array_reverse($files);
                                        $shows = array_slice($files, $per_page * intval($_GET['page']) - 12, $per_page);
                                        rsort($files);
                                        if($_GET['page'] > 1)
                                            $prev_link = '<a href="ffp3-gallery.php?page='.($_GET['page']-1).'" class="button primary fit"> page précédente </a>';
                                        if($_GET['page'] < $max_pages)
                                            $next_link = '<a href="ffp3-gallery.php?page='.($_GET['page']+1).'" class="button primary fit"> page suivante </a>';
                                        foreach ($shows as $show) {
                                          if ($show != '.' && $show != '..') {
                                      ?>
                                     	<div class="col-4">
                                 	        <span class="image fit">
                                                <a href="<?php echo $dir . $show; ?>" data-featherlight="<?php echo $dir . $show; ?>">
                                                    <img src="<?php echo $dir . $show; ?>" alt="" title="<?php echo $show; ?>" />
                                                    <?php echo $show; ?>
                                                </a>
                                            </span>
                                        </div>
                                    <?php
                                          // $count++;
                                          }
                                        }
                                      }
                                     // if($count==1) { echo "<p>No images found</p>"; } 
                                    ?>

										<div class="col-6 col-12-small">
											<ul class="actions stacked">
		                                      <p><?php echo $prev_link; ?></p>
											</ul>
										</div>
										<div class="col-6 col-12-small">
											<ul class="actions stacked">
                                                <p><?php echo $next_link; ?></p>
											</ul>
										</div>
	                             </div> 
                                 <hr />
					             <a href="https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php" class="button large">Retour aux données</a>
							<!--</section>-->
						</article>
					</div>	
				</div>

		 <!--Scripts -->
			<script src="https://iot.olution.info/assets/js/jquery.min.js"></script>
			<script src="https://iot.olution.info/assets/js/jquery.scrollex.min.js"></script>
			<script src="https://iot.olution.info/assets/js/jquery.scrolly.min.js"></script>
			<script src="https://iot.olution.info/assets/js/browser.min.js"></script>
			<script src="https://iot.olution.info/assets/js/breakpoints.min.js"></script>
			<script src="https://iot.olution.info/assets/js/util.js"></script>
			<script src="https://iot.olution.info/assets/js/main.js"></script>
			<script src="//code.jquery.com/jquery-latest.js"></script>
            <script src="//cdn.jsdelivr.net/npm/featherlight@1.7.14/release/featherlight.min.js" type="text/javascript" charset="utf-8"></script>

	</body>
</html>