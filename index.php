<?php
	
	// for timestamps conversion
	// please see https://github.com/cwal/convert-timestamp-to-time-ago for this script
	include('timestampConvertToTimeAgo.php');
	
	// connection details
	$servername = "localhost";
	$username = "";
	$password = "";
	$dbname = "";
		
	try 
	{
		$conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
		$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		//echo "Connected successfully"; 
	}
	catch(PDOException $e)
	{
		echo "Connection failed: " . $e->getMessage();
	}

	// find all the comments associated with a certain thread
	function findCommentsByRelatedId($related_id)
	{
		global $conn;
		
		$sql = 	"
			SELECT  UserComment.*, (COUNT(Ghost.id) - 1) AS depth
			FROM (user_comments AS UserComment, user_comments as Ghost)
								    
			WHERE	UserComment.lft BETWEEN Ghost.lft AND Ghost.rgt
			AND		Ghost.related_id = :related_id
			AND		UserComment.related_id = :related_id
			AND		UserComment.parent_id is not null
								
			GROUP BY UserComment.id
			ORDER BY UserComment.lft
			LIMIT 50
			"; // arbitrary limit
		$stmt = $conn->prepare($sql);
		$stmt->execute(array('related_id' => $related_id));
		$res = $stmt->fetchAll();
		if (!empty($res))
		{
			return $res;
		}
	
		return array(); // return empty array

	}
	
	// get the parent id for a new top level comment in a thread
	// if the thread has no new comments, return is 0
	function getParentIdByRelatedId($related_id)
	{
		global $conn;
		
		$sql = 	"
			SELECT  UserComment.*
			FROM user_comments AS UserComment
			
			WHERE	UserComment.related_id = :related_id
			AND	UserComment.parent_id is null
			LIMIT 1
			";
		
		$stmt = $conn->prepare($sql);
		$stmt->execute(array('related_id' => $related_id));
		$res = $stmt->fetch();
		return empty($res) ? 0 : $res['id'];
	}
	
	// Save a new comment
	function saveUserComment($comment, $related_id, $parent_id)
	{
		global $conn;
		
		if($parent_id == 0) // new thread, lets create a ghost comment
		{
			$sql = 	"
				INSERT INTO user_comments (comment, related_id, created, modified, lft, rgt, parent_id) VALUES (:comment, :related_id, :created, :modified, :lft, :rgt, :parent_id)
				";
		
			$stmt = $conn->prepare($sql);
			$ghost_res = $stmt->execute(array('comment' => '', 'related_id' => $related_id, 'created' => date("Y-m-d H:i:s"), 'modified' => date("Y-m-d H:i:s"), 'lft' => 1, 'rgt' => 2, 'parent_id' => NULL));
			$parent_id = $conn->lastInsertId();
		}
		
		// insert the new comment
		$sql = 	"
			INSERT INTO user_comments (comment, related_id, created, modified, parent_id) VALUES (:comment, :related_id, :created, :modified, :parent_id)
			";
		
		$stmt = $conn->prepare($sql);
		$stmt->execute(array('comment' => $comment, 'related_id' => $related_id, 'created' => date("Y-m-d H:i:s"), 'modified' => date("Y-m-d H:i:s"), 'parent_id' => $parent_id));
		$inserted_id = $conn->lastInsertId();
		
		// update lft/rgt values for all associated comments
		$sql =	"SELECT @myRight := rgt FROM user_comments WHERE id = :parent_id;" . // get the parent comments rgt value, set it as a mysql variable to be used below
			"UPDATE user_comments SET lft = lft + 2 WHERE related_id = :related_id AND lft >= @myRight;" . // any comment with a lft greater than @myRight, add 2 to it
			"UPDATE user_comments SET rgt = rgt + 2 WHERE related_id = :related_id AND rgt >= @myRight;" . // any comment with a rgt greater than @myRight, add 2 to it
			"UPDATE user_comments SET lft = @myRight WHERE id = :inserted_id;" . // updated the newly inserted comment with a lft value equal to the @myRight value
			"UPDATE user_comments SET rgt = @myRight + 1 WHERE id = :inserted_id;"; // updated the newly inserted comment with a rgt value equal to the @myRight value + 1
		$stmt = $conn->prepare($sql);
		$stmt->execute(array('related_id' => $related_id, 'parent_id' => $parent_id, 'inserted_id' => $inserted_id));
		
		return true;
	}
	
	if(!empty($_POST))
	{
		// comment is posted
		saveUserComment($_POST['comment'], $_POST['related_id'], $_POST['parent_id']);
	}

?>
<!doctype html>

<html lang="en">
<head>
	<meta charset="utf-8">

	<title>Nested Comments</title>
	<meta name="description" content="Nested Comments">
	<meta name="author" content="Christopher Waldau">

	<!--[if lt IE 9]>
	<script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
	<![endif]-->
</head>

<body>
	
	<h1>Nested Comments Thread #1</h1>
	
	<?php
		$related_id = 1;
	?>
	
	<form action="<?php echo $_SERVER['SELF']; ?>" method="post">
		<textarea name="comment" id="comment">Create a new top level comment in thread #1</textarea>
		<input type="hidden" name="related_id" value="<?php echo $related_id; ?>">
		<input type="hidden" name="parent_id" value="<?php echo getParentIdByRelatedId($related_id); ?>">
		<input type="submit" name="submit_btn">
	</form><br>
	
	<?php
		$count = 0;
		foreach(findCommentsByRelatedId($related_id) as $comments):
			if($comments['depth'] == 1) $count++;
	?>
		<span style="margin-left:<?php echo $comments['depth'] * 2; ?>0px;"><b><?php echo 'Comment #' . $count . ' - Reply #' . $comments['depth'] . ': ';?></b> <i>(posted <?php echo timestampConvertToTimeAgo($comments['created']); ?>)</i></span><br>
		<span style="margin-left:<?php echo $comments['depth'] * 2; ?>0px;"><?php echo $comments['comment']; ?></span>
		<form action="<?php echo $_SERVER['SELF']; ?>" method="post" style="margin-left:<?php echo $comments['depth'] * 2; ?>0px;">
			<textarea name="comment" id="comment">Reply to this comment</textarea>
			<input type="hidden" name="related_id" value="<?php echo $related_id; ?>">
			<input type="hidden" name="parent_id" value="<?php echo $comments['id']; ?>">
			<input type="submit" name="submit_btn">
		</form>
	<?php
		endforeach;
	?>
	
	<h1>Nested Comments Thread #2</h1>
	
	<?php
		$related_id = 2;
	?>
	
	<form action="<?php echo $_SERVER['SELF']; ?>" method="post">
		<textarea name="comment" id="comment">Create a new top level comment in thread #2</textarea>
		<input type="hidden" name="related_id" value="<?php echo $related_id; ?>">
		<input type="hidden" name="parent_id" value="<?php echo getParentIdByRelatedId($related_id); ?>">
		<input type="submit" name="submit_btn">
	</form><br>
	
	<?php
		$count = 0;
		foreach(findCommentsByRelatedId($related_id) as $comments):
			if($comments['depth'] == 1) $count++;
	?>
		<span style="margin-left:<?php echo $comments['depth'] * 2; ?>0px;"><b><?php echo 'Comment #' . $count . ' - Reply #' . $comments['depth'] . ': ';?></b> <i>(posted <?php echo timestampConvertToTimeAgo($comments['created']); ?>)</i></span><br>
		<span style="margin-left:<?php echo $comments['depth'] * 2; ?>0px;"><?php echo $comments['comment']; ?></span>
		<form action="<?php echo $_SERVER['SELF']; ?>" method="post" style="margin-left:<?php echo $comments['depth'] * 2; ?>0px;">
			<textarea name="comment" id="comment">Reply to this comment</textarea>
			<input type="hidden" name="parent_id" value="<?php echo $comments['id']; ?>">
			<input type="hidden" name="related_id" value="<?php echo $related_id; ?>">
			<input type="submit" name="submit_btn">
		</form>
	<?php
		endforeach;
	?>
</body>
</html>
