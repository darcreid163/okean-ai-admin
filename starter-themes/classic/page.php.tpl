<?php get_header(); ?><main class="flex-1"><?php if(have_posts()):while(have_posts()):the_post(); ?><article class="max-w-3xl mx-auto px-6 py-16"><header class="mb-10"><h1 class="text-4xl md:text-5xl font-bold tracking-tight text-neutral"><?php the_title(); ?></h1></header><div class="prose max-w-none"><?php the_content(); ?></div></article><?php endwhile;endif; ?></main><?php get_footer(); ?>

