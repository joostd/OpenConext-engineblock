-- Add new emails for deprovisioning
INSERT INTO `emails` (`id`, `email_type`, `email_text`, `email_from`, `email_subject`, `is_html`)
VALUES
	(2, 'deprovisioning_warning_email', 'Dear {user},<br />\r\n<br />\r\nThis mail is to inform you that you will be deprovisioned at {deprovision_time}. If you have any questions regarding this mail please contact help@surfconext.nl.<br />\r\n<br />\r\nBest regards<br />, SURFconext', 'help@surfconext.nl', 'Deprovisioning SURFconext', NULL),
	(3, 'deprovisioning_warning_email_group_members', 'Dear {user},<br />\r\n<br />\r\nThis mail is to inform you that your administrator in team {team} will be deprovisioned at {deprovision_time}. If you have any questions regarding this mail please contact help@surfconext.nl.<br />\r\n<br />\r\nBest regards<br />, SURFconext', 'help@surfconext.nl', 'Deprovisioning  SURFconext', NULL);
