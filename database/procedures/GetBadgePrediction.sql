DELIMITER $$
CREATE DEFINER=`admin`@`localhost` PROCEDURE `GetBadgePrediction`(IN `agent_name` VARCHAR(15), IN `stat_key` VARCHAR(20))
    READS SQL DATA
BEGIN

DROP TABLE IF EXISTS BadgePrediction;

SELECT count(*),
       SUM(timepoint),
       SUM(value),
       SUM(timepoint * timepoint),
       SUM(value * timepoint)
  INTO @n,
       @sumX,
       @sumY,
       @sumX2,
       @sumXY
  FROM Data 
 WHERE agent = agent_name AND
       stat = stat_key AND
       value > 0 AND
       date >= DATE_SUB(NOW(), INTERVAL 30 DAY);

SELECT (((@n * @sumXY) - (@sumX * @sumY)) / ((@n * @sumX2) - (@sumX * @sumX))) INTO @slope;
SELECT ((@sumY - (@slope * @sumX)) / @n) INTO @intercept;

SELECT value 
  INTO @min
  FROM Data 
 WHERE agent = agent_name AND
       stat = stat_key AND
       value > 0 AND
       date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
HAVING MIN(timepoint);

  SELECT value 
    INTO @max
    FROM Data
   WHERE agent = agent_name AND
         stat = stat_key
ORDER BY date DESC 
   LIMIT 1;

SELECT name,
       unit
  INTO @stat,
       @unit
  FROM Stats
 WHERE stat = stat_key;

SELECT name INTO @badge FROM Badges WHERE stat = stat_key LIMIT 1;

SELECT level INTO @current FROM Badges WHERE stat = stat_key AND amount_required <= @max ORDER BY amount_required DESC LIMIT 1;
SELECT level INTO @next    FROM Badges WHERE stat = stat_key AND amount_required >  @max ORDER BY amount_required ASC  LIMIT 1;

SELECT amount_required - @max INTO @remaining FROM Badges WHERE stat = stat_key AND level = @next;

SELECT amount_required INTO @y FROM Badges WHERE stat = stat_key AND level = @next;

SELECT @remaining / @slope INTO @days;

IF stat_key = 'oldest_portal' THEN
	SELECT @remaining INTO @days;
END IF;

IF @days < 0 THEN
	SELECT 0 INTO @days;
END IF;

CALL UpdateCache(agent_name, stat_key, 'rate', @slope);
CALL UpdateCache(agent_name, stat_key, 'intercept', @intercept);

CREATE TEMPORARY TABLE BadgePrediction
	SELECT stat_key `stat`, 
	       @stat `name`,
           @unit `unit`,
	       @badge `badge`,
	       @current `current`,
	       @next `next`,
	       ROUND(@max / @y, 2) `progress`,
	       @max `obtained`,
	       @remaining `remaining`,
	       ROUND(@days, 1) `days`,
	       ROUND(@slope, 0) `rate`,
	       ROUND(@intercept) `intercept`,
	       ROUND(@slope, 2) `slope`;

END $$
DELIMITER ;
