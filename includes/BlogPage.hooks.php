<?php
/**
 * All BlogPage's hooked functions. These were previously scattered all over
 * the place in various files.
 *
 * @file
 */
class BlogPageHooks {

	/**
	 * Calls BlogPage instead of standard Article for pages in the NS_BLOG
	 * namespace.
	 *
	 * @param Title $title
	 * @param Article|BlogPage $article Instance of Article that we convert into a BlogPage
	 * @return bool
	 */
	public static function blogFromTitle( &$title, &$article ) {
		global $wgHooks, $wgOut;

		if ( $title->getNamespace() == NS_BLOG ) {
			// This will suppress category links in SkinTemplate-based skins
			$wgHooks['SkinTemplateOutputPageBeforeExec'][] = function ( $sk, $tpl ) {
				$tpl->set( 'catlinks', '' );
				return true;
			};

			$wgOut->enableClientCache( false );

			// Add CSS
			$wgOut->addModuleStyles( 'ext.blogPage' );

			$article = new BlogPage( $title );
		}

		return true;
	}

	/**
	 * Checks that the user is logged is, is not blocked via Special:Block and has
	 * the 'edit' user right when they're trying to edit a page in the NS_BLOG NS.
	 *
	 * @param EditPage $editPage
	 * @return bool True if the user should be allowed to continue, else false
	 */
	public static function allowShowEditBlogPage( $editPage ) {
		$context = $editPage->getArticle()->getContext();
		$output = $context->getOutput();
		$user = $context->getUser();

		if ( $editPage->mTitle->getNamespace() == NS_BLOG ) {
			if ( $user->isAnon() ) { // anons can't edit blog pages
				if ( !$editPage->mTitle->exists() ) {
					$output->addWikiMsg( 'blog-login' );
				} else {
					$output->addWikiMsg( 'blog-login-edit' );
				}
				return false;
			}

			if ( !$user->isAllowed( 'edit' ) || $user->isBlocked() ) {
				$output->addWikiMsg( 'blog-permission-required' );
				return false;
			}
		}

		return true;
	}

	/**
	 * This function was originally in the UserStats directory, in the file
	 * CreatedOpinionsCount.php.
	 * This function here updates the stats_opinions_created column in the
	 * user_stats table every time the user creates a new blog post.
	 *
	 * This is hooked into two separate hooks (todo: find out why), PageContentSave
	 * and PageContentSaveComplete. Their arguments are mostly the same and both
	 * have $wikiPage as the first argument.
	 *
	 * @param WikiPage $wikiPage WikiPage object representing the page that was/is
	 *                         (being) saved
	 *
	 * @return bool
	 */
	public static function updateCreatedOpinionsCount( &$wikiPage, &$user ) {
		$aid = $wikiPage->getTitle()->getArticleID();
		// Shortcut, in order not to perform stupid queries (cl_from = 0...)
		if ( $aid == 0 ) {
			return true;
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'categorylinks',
			'cl_to',
			[ 'cl_from' => $aid ],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$ctg = Title::makeTitle( NS_CATEGORY, $row->cl_to );
			$ctgname = $ctg->getText();
			$userBlogCat = wfMessage( 'blog-by-user-category' )->inContentLanguage()->text();

			// @todo CHECKME/FIXME: This probably no longer works as intended
			// due to the recent (as of 20 September 2014) i18n message change
			if ( strpos( $ctgname, $userBlogCat ) !== false ) {
				$user_name = trim( str_replace( $userBlogCat, '', $ctgname ) );
				$u = User::idFromName( $user_name );

				if ( $u ) {
					$stats = new UserStatsTrack( $u, $user_name );
					$userBlogCat = wfMessage( 'blog-by-user-category', $stats->user_name )
						->inContentLanguage()->text();
					// Copied from UserStatsTrack::updateCreatedOpinionsCount()
					// Throughout this code, we could use $u and $user_name
					// instead of $stats->user_id and $stats->user_name but
					// there's no point in doing that because we have to call
					// clearCache() in any case
					if ( !$user->isAnon() && $stats->user_id ) {
						$parser = new Parser();
						$ctgTitle = Title::newFromText(
							$parser->preprocess(
								trim( $userBlogCat ),
								$wikiPage->getContext()->getTitle(),
								$wikiPage->getContext()->getOutput()->parserOptions()
							)
						);
						$ctgTitle = $ctgTitle->getDBkey();
						$dbw = wfGetDB( DB_MASTER );

						$opinions = $dbw->select(
							[ 'page', 'categorylinks' ],
							[ 'COUNT(*) AS CreatedOpinions' ],
							[
								'cl_to' => $ctgTitle,
								'page_namespace' => NS_BLOG // paranoia
							],
							__METHOD__,
							[],
							[
								'categorylinks' => [
									'INNER JOIN',
									'page_id = cl_from'
								]
							]
						);

						// Please die in a fire, PHP.
						// selectField() would be ideal above but it returns
						// insane results (over 300 when the real count is
						// barely 10) so we have to fuck around with a
						// foreach() loop that we don't even need in theory
						// just because PHP is...PHP.
						$opinionsCreated = 0;
						foreach ( $opinions as $opinion ) {
							$opinionsCreated = $opinion->CreatedOpinions;
						}

						$res = $dbw->update(
							'user_stats',
							[ 'stats_opinions_created' => $opinionsCreated ],
							[ 'stats_user_id' => $stats->user_id ],
							__METHOD__
						);

						$stats->clearCache();
					}
				}
			}
		}

		return true;
	}

	/**
	 * Show a list of this user's blog articles in their user profile page.
	 *
	 * @param UserProfilePage $userProfile
	 * @return bool
	 */
	public static function getArticles( $userProfile ) {
		global $wgUserProfileDisplay, $wgMemc, $wgOut;

		if ( !$wgUserProfileDisplay['articles'] ) {
			return '';
		}

		$user_name = $userProfile->user_name;
		$output = '';

		// Try cache first
		$key = $wgMemc->makeKey( 'user', 'profile', 'articles', $userProfile->user_id );
		$data = $wgMemc->get( $key );
		$articles = [];

		if ( $data != '' ) {
			wfDebugLog(
				'BlogPage',
				"Got UserProfile articles for user {$user_name} from cache\n"
			);
			$articles = $data;
		} else {
			wfDebugLog(
				'BlogPage',
				"Got UserProfile articles for user {$user_name} from DB\n"
			);
			$categoryTitle = Title::newFromText(
				wfMessage(
					'blog-by-user-category',
					$user_name
				)->inContentLanguage()->text()
			);

			$dbr = wfGetDB( DB_REPLICA );
			/**
			 * I changed the original query a bit, since it wasn't returning
			 * what it should've.
			 * I added the DISTINCT to prevent one page being listed five times
			 * and added the page_namespace to the WHERE clause to get only
			 * blog pages and the cl_from = page_id to the WHERE clause so that
			 * the cl_to stuff actually, y'know, works :)
			 */
			$res = $dbr->select(
				[ 'page', 'categorylinks' ],
				[ 'DISTINCT page_id', 'page_title', 'page_namespace' ],
				/* WHERE */[
					'cl_from = page_id',
					'cl_to' => [ $categoryTitle->getDBkey() ],
					'page_namespace' => NS_BLOG
				],
				__METHOD__,
				[ 'ORDER BY' => 'page_id DESC', 'LIMIT' => 5 ]
			);

			foreach ( $res as $row ) {
				$articles[] = [
					'page_title' => $row->page_title,
					'page_namespace' => $row->page_namespace,
					'page_id' => $row->page_id
				];
			}

			$wgMemc->set( $key, $articles, 60 );
		}

		// Load opinion count via user stats;
		$stats = new UserStats( $userProfile->user_id, $user_name );
		$stats_data = $stats->getUserStats();
		$articleCount = $stats_data['opinions_created'];

		$articleLink = Title::makeTitle(
			NS_CATEGORY,
			wfMessage(
				'blog-by-user-category',
				$user_name
			)->inContentLanguage()->text()
		);

		if ( count( $articles ) > 0 ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'blog-user-articles-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">';
			if ( $articleCount > 5 ) {
				$output .= '<a href="' . htmlspecialchars( $articleLink->getFullURL() ) .
					'" rel="nofollow">' . wfMessage( 'user-view-all' )->escaped() . '</a>';
			}
			$output .= '</div>
					<div class="action-left">' .
					wfMessage( 'user-count-separator' )
						->numParams( $articleCount, count( $articles ) )
						->escaped() . '</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="user-articles-container">';

			$x = 1;

			foreach ( $articles as $article ) {
				$articleTitle = Title::makeTitle(
					$article['page_namespace'],
					$article['page_title']
				);
				$voteCount = BlogPage::getVotesForPage( $article['page_id'] );
				$commentCount = BlogPage::getCommentsForPage( $article['page_id'] );

				if ( $x == 1 ) {
					$divClass = 'article-item-top';
				} else {
					$divClass = 'article-item';
				}
				$output .= '<div class="' . $divClass . "\">
					<div class=\"number-of-votes\">
						<div class=\"vote-number\">{$voteCount}</div>
						<div class=\"vote-text\">" .
							wfMessage( 'blog-user-articles-votes' )
								->numParams( $voteCount )
								->escaped() .
						'</div>
					</div>
					<div class="article-title">
						<a href="' . htmlspecialchars( $articleTitle->getFullURL() ) .
							'">' . htmlspecialchars( $articleTitle->getText() ) . '</a>
						<span class="item-small">' .
							wfMessage( 'blog-user-article-comment' )
								->numParams( $commentCount )
								->escaped() . '</span>
					</div>
					<div class="visualClear"></div>
				</div>';

				$x++;
			}

			$output .= '</div>';
		}

		$wgOut->addHTML( $output );

		return true;
	}

	/**
	 * Register the canonical names for our namespace and its talkspace.
	 *
	 * @param array $list Array of namespace numbers with corresponding
	 *                     canonical names
	 * @return bool
	 */
	public static function onCanonicalNamespaces( &$list ) {
		$list[NS_BLOG] = 'Blog';
		$list[NS_BLOG_TALK] = 'Blog_talk';
		return true;
	}
}
