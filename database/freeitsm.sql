USE [FREEITSM]
GO
/****** Object:  Table [dbo].[analyst_teams]    Script Date: 07/02/2026 16:27:15 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[analyst_teams](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[analyst_id] [int] NOT NULL,
	[team_id] [int] NOT NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UQ_analyst_team] UNIQUE NONCLUSTERED 
(
	[analyst_id] ASC,
	[team_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[analysts]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[analysts](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[username] [nvarchar](50) NOT NULL,
	[password_hash] [nvarchar](255) NOT NULL,
	[full_name] [nvarchar](100) NOT NULL,
	[email] [nvarchar](100) NOT NULL,
	[is_active] [bit] NULL,
	[created_datetime] [datetime] NULL,
	[last_login_datetime] [datetime] NULL,
	[last_modified_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
	[username] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[apikeys]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[apikeys](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[apikey] [nvarchar](50) NULL,
	[datestamp] [datetime] NULL,
	[active] [bit] NULL
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[assets]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[assets](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[hostname] [nvarchar](20) NULL,
	[manufacturer] [nvarchar](50) NULL,
	[model] [nvarchar](50) NULL,
	[memory] [numeric](18, 0) NULL,
	[service_tag] [nvarchar](20) NULL,
	[operating_system] [nvarchar](50) NULL,
	[feature_release] [nvarchar](10) NULL,
	[build_number] [nvarchar](50) NULL,
	[cpu_name] [nvarchar](250) NULL,
	[speed] [numeric](18, 0) NULL,
	[bios_version] [nvarchar](20) NULL,
	[first_seen] [datetime] NULL,
	[last_seen] [datetime] NULL,
 CONSTRAINT [PK_assets] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[calendar_categories]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[calendar_categories](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](100) NOT NULL,
	[color] [nvarchar](7) NOT NULL,
	[description] [nvarchar](500) NULL,
	[is_active] [bit] NOT NULL,
	[created_at] [datetime] NOT NULL,
	[updated_at] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[calendar_events]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[calendar_events](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[title] [nvarchar](255) NOT NULL,
	[description] [nvarchar](max) NULL,
	[category_id] [int] NULL,
	[start_datetime] [datetime] NOT NULL,
	[end_datetime] [datetime] NULL,
	[all_day] [bit] NOT NULL,
	[location] [nvarchar](255) NULL,
	[created_by] [int] NOT NULL,
	[created_at] [datetime] NOT NULL,
	[updated_at] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[change_attachments]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[change_attachments](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[change_id] [int] NOT NULL,
	[file_name] [nvarchar](255) NOT NULL,
	[file_path] [nvarchar](500) NOT NULL,
	[file_size] [int] NULL,
	[file_type] [nvarchar](100) NULL,
	[uploaded_by_id] [int] NULL,
	[uploaded_datetime] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[changes]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[changes](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[title] [nvarchar](255) NOT NULL,
	[change_type] [nvarchar](20) NOT NULL,
	[status] [nvarchar](30) NOT NULL,
	[priority] [nvarchar](20) NOT NULL,
	[impact] [nvarchar](20) NOT NULL,
	[category] [nvarchar](100) NULL,
	[requester_id] [int] NULL,
	[assigned_to_id] [int] NULL,
	[approver_id] [int] NULL,
	[approval_datetime] [datetime] NULL,
	[work_start_datetime] [datetime] NULL,
	[work_end_datetime] [datetime] NULL,
	[outage_start_datetime] [datetime] NULL,
	[outage_end_datetime] [datetime] NULL,
	[description] [nvarchar](max) NULL,
	[reason_for_change] [nvarchar](max) NULL,
	[risk_evaluation] [nvarchar](max) NULL,
	[test_plan] [nvarchar](max) NULL,
	[rollback_plan] [nvarchar](max) NULL,
	[post_implementation_review] [nvarchar](max) NULL,
	[created_by_id] [int] NULL,
	[created_datetime] [datetime] NOT NULL,
	[modified_datetime] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[department_teams]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[department_teams](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[department_id] [int] NOT NULL,
	[team_id] [int] NOT NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UQ_department_team] UNIQUE NONCLUSTERED 
(
	[department_id] ASC,
	[team_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[departments]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[departments](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](100) NOT NULL,
	[description] [nvarchar](255) NULL,
	[is_active] [bit] NULL,
	[display_order] [int] NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
	[name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[email_attachments]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[email_attachments](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[email_id] [int] NOT NULL,
	[exchange_attachment_id] [nvarchar](255) NULL,
	[filename] [nvarchar](255) NOT NULL,
	[content_type] [nvarchar](100) NOT NULL,
	[content_id] [nvarchar](255) NULL,
	[file_path] [nvarchar](500) NOT NULL,
	[file_size] [int] NOT NULL,
	[is_inline] [bit] NOT NULL,
	[created_datetime] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[emails]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[emails](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[exchange_message_id] [nvarchar](255) NULL,
	[subject] [nvarchar](500) NULL,
	[from_address] [nvarchar](255) NOT NULL,
	[from_name] [nvarchar](255) NULL,
	[to_recipients] [nvarchar](max) NULL,
	[cc_recipients] [nvarchar](max) NULL,
	[received_datetime] [datetime] NULL,
	[body_preview] [nvarchar](max) NULL,
	[body_content] [nvarchar](max) NULL,
	[body_type] [nvarchar](20) NULL,
	[has_attachments] [bit] NULL,
	[importance] [nvarchar](20) NULL,
	[is_read] [bit] NULL,
	[processed_datetime] [datetime] NULL,
	[ticket_created] [bit] NULL,
	[ticket_id] [int] NULL,
	[department_id] [int] NULL,
	[ticket_type_id] [int] NULL,
	[assigned_analyst_id] [int] NULL,
	[status] [nvarchar](50) NULL,
	[assigned_datetime] [datetime] NULL,
	[is_initial] [bit] NULL,
	[direction] [nvarchar](20) NULL,
	[mailbox_id] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[form_fields]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[form_fields](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[form_id] [int] NOT NULL,
	[field_type] [nvarchar](50) NOT NULL,
	[label] [nvarchar](255) NOT NULL,
	[options] [nvarchar](max) NULL,
	[is_required] [bit] NOT NULL,
	[sort_order] [int] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[form_submission_data]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[form_submission_data](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[submission_id] [int] NOT NULL,
	[field_id] [int] NOT NULL,
	[field_value] [nvarchar](max) NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[form_submissions]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[form_submissions](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[form_id] [int] NOT NULL,
	[submitted_by] [int] NULL,
	[submitted_date] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[forms]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[forms](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[title] [nvarchar](255) NOT NULL,
	[description] [nvarchar](max) NULL,
	[is_active] [bit] NOT NULL,
	[created_by] [int] NULL,
	[created_date] [datetime] NOT NULL,
	[modified_date] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[knowledge_article_tags]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[knowledge_article_tags](
	[article_id] [int] NOT NULL,
	[tag_id] [int] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[article_id] ASC,
	[tag_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[knowledge_articles]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[knowledge_articles](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[title] [nvarchar](255) NOT NULL,
	[body] [nvarchar](max) NULL,
	[author_id] [int] NOT NULL,
	[created_datetime] [datetime] NULL,
	[modified_datetime] [datetime] NULL,
	[is_published] [bit] NULL,
	[view_count] [int] NULL,
	[next_review_date] [date] NULL,
	[owner_id] [int] NULL,
	[embedding] [nvarchar](max) NULL,
	[embedding_updated] [datetime] NULL,
	[is_archived] [bit] NULL,
	[archived_datetime] [datetime] NULL,
	[archived_by_id] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[knowledge_tags]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[knowledge_tags](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](50) NOT NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
	[name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[morningChecks_Checks]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[morningChecks_Checks](
	[CheckID] [int] IDENTITY(1,1) NOT NULL,
	[CheckName] [nvarchar](255) NOT NULL,
	[CheckDescription] [nvarchar](max) NULL,
	[IsActive] [bit] NOT NULL,
	[SortOrder] [int] NOT NULL,
	[CreatedDate] [datetime] NOT NULL,
	[ModifiedDate] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[CheckID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[morningChecks_Results]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[morningChecks_Results](
	[ResultID] [int] IDENTITY(1,1) NOT NULL,
	[CheckID] [int] NOT NULL,
	[CheckDate] [datetime] NOT NULL,
	[Status] [nvarchar](10) NOT NULL,
	[Notes] [nvarchar](max) NULL,
	[CreatedBy] [nvarchar](100) NULL,
	[CreatedDate] [datetime] NOT NULL,
	[ModifiedDate] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[ResultID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UQ_CheckDate] UNIQUE NONCLUSTERED 
(
	[CheckID] ASC,
	[CheckDate] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[servers]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[servers](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[vm_id] [nvarchar](100) NOT NULL,
	[name] [nvarchar](255) NULL,
	[power_state] [nvarchar](20) NULL,
	[memory_gb] [decimal](10, 2) NULL,
	[num_cpu] [int] NULL,
	[ip_address] [nvarchar](50) NULL,
	[hard_disk_size_gb] [decimal](10, 2) NULL,
	[host] [nvarchar](255) NULL,
	[cluster] [nvarchar](255) NULL,
	[guest_os] [nvarchar](255) NULL,
	[last_synced] [datetime] NULL,
	[raw_data] [varchar](max) NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[software_inventory_apps]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[software_inventory_apps](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[display_name] [nvarchar](512) NOT NULL,
	[publisher] [nvarchar](512) NULL,
	[first_detected] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UX_App_Display_Publisher] UNIQUE NONCLUSTERED 
(
	[display_name] ASC,
	[publisher] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[software_inventory_detail]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[software_inventory_detail](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[host_id] [int] NOT NULL,
	[app_id] [int] NOT NULL,
	[display_version] [nvarchar](100) NULL,
	[install_date] [nvarchar](50) NULL,
	[uninstall_string] [nvarchar](max) NULL,
	[install_location] [nvarchar](max) NULL,
	[estimated_size] [nvarchar](100) NULL,
	[created_at] [datetime] NOT NULL,
	[last_seen] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UX_SoftwareDetail_Host_App] UNIQUE NONCLUSTERED 
(
	[host_id] ASC,
	[app_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[system_logs]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[system_logs](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[log_type] [nvarchar](50) NOT NULL,
	[created_datetime] [datetime] NULL,
	[analyst_id] [int] NULL,
	[details] [nvarchar](max) NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[system_settings]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[system_settings](
	[setting_key] [nvarchar](100) NOT NULL,
	[setting_value] [nvarchar](max) NULL,
	[updated_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[setting_key] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[target_mailboxes]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[target_mailboxes](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](100) NOT NULL,
	[azure_tenant_id] [nvarchar](100) NOT NULL,
	[azure_client_id] [nvarchar](100) NOT NULL,
	[azure_client_secret] [nvarchar](255) NOT NULL,
	[oauth_redirect_uri] [nvarchar](500) NOT NULL,
	[oauth_scopes] [nvarchar](500) NOT NULL,
	[imap_server] [nvarchar](255) NOT NULL,
	[imap_port] [int] NOT NULL,
	[imap_encryption] [nvarchar](10) NOT NULL,
	[target_mailbox] [nvarchar](255) NOT NULL,
	[token_data] [nvarchar](max) NULL,
	[email_folder] [nvarchar](100) NOT NULL,
	[max_emails_per_check] [int] NOT NULL,
	[mark_as_read] [bit] NOT NULL,
	[is_active] [bit] NOT NULL,
	[created_datetime] [datetime] NOT NULL,
	[last_checked_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[teams]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[teams](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](100) NOT NULL,
	[description] [nvarchar](500) NULL,
	[display_order] [int] NULL,
	[is_active] [bit] NULL,
	[created_datetime] [datetime] NULL,
	[updated_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[ticket_audit]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[ticket_audit](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[ticket_id] [int] NOT NULL,
	[analyst_id] [int] NOT NULL,
	[field_name] [nvarchar](100) NOT NULL,
	[old_value] [nvarchar](500) NULL,
	[new_value] [nvarchar](500) NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[ticket_notes]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[ticket_notes](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[ticket_id] [int] NOT NULL,
	[analyst_id] [int] NOT NULL,
	[note_text] [nvarchar](max) NOT NULL,
	[is_internal] [bit] NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[ticket_origins]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[ticket_origins](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](100) NOT NULL,
	[description] [nvarchar](255) NULL,
	[display_order] [int] NULL,
	[is_active] [bit] NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[ticket_prefixes]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[ticket_prefixes](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[prefix] [nvarchar](3) NOT NULL,
	[description] [nvarchar](100) NULL,
	[department_id] [int] NULL,
	[is_default] [bit] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
	[prefix] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[ticket_types]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[ticket_types](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](100) NOT NULL,
	[description] [nvarchar](255) NULL,
	[is_active] [bit] NULL,
	[display_order] [int] NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
	[name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[tickets]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[tickets](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[ticket_number] [nvarchar](50) NOT NULL,
	[subject] [nvarchar](500) NOT NULL,
	[status] [nvarchar](50) NULL,
	[priority] [nvarchar](50) NULL,
	[department_id] [int] NULL,
	[ticket_type_id] [int] NULL,
	[assigned_analyst_id] [int] NULL,
	[requester_email] [nvarchar](255) NULL,
	[requester_name] [nvarchar](255) NULL,
	[created_datetime] [datetime] NULL,
	[updated_datetime] [datetime] NULL,
	[closed_datetime] [datetime] NULL,
	[origin_id] [int] NULL,
	[first_time_fix] [bit] NULL,
	[it_training_provided] [bit] NULL,
	[user_id] [int] NULL,
	[owner_id] [int] NULL,
	[work_start_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
	[ticket_number] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[users]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[users](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[email] [nvarchar](255) NOT NULL,
	[display_name] [nvarchar](255) NULL,
	[created_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UQ_users_email] UNIQUE NONCLUSTERED 
(
	[email] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[users_assets]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[users_assets](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[user_id] [int] NOT NULL,
	[asset_id] [int] NOT NULL,
	[assigned_datetime] [datetime] NULL,
	[assigned_by_analyst_id] [int] NULL,
	[notes] [nvarchar](500) NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UQ_user_asset] UNIQUE NONCLUSTERED 
(
	[user_id] ASC,
	[asset_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[asset_history]    Script Date: 14/02/2026 00:00:00 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[asset_history](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[asset_id] [int] NOT NULL,
	[analyst_id] [int] NOT NULL,
	[field_name] [nvarchar](100) NOT NULL,
	[old_value] [nvarchar](500) NULL,
	[new_value] [nvarchar](500) NULL,
	[created_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_classes]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_classes](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[file_id] [int] NOT NULL,
	[class_name] [nvarchar](255) NOT NULL,
	[line_number] [int] NOT NULL,
	[extends_class] [nvarchar](255) NULL,
	[implements_interfaces] [nvarchar](max) NULL,
	[description] [nvarchar](max) NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_db_references]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_db_references](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[file_id] [int] NOT NULL,
	[table_name] [nvarchar](255) NOT NULL,
	[reference_type] [nvarchar](50) NOT NULL,
	[line_number] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_dependencies]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_dependencies](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[file_id] [int] NOT NULL,
	[dependency_type] [nvarchar](50) NOT NULL,
	[target_path] [nvarchar](500) NOT NULL,
	[resolved_file_id] [int] NULL,
	[line_number] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_files]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_files](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[scan_id] [int] NOT NULL,
	[file_path] [nvarchar](500) NOT NULL,
	[file_name] [nvarchar](255) NOT NULL,
	[folder_path] [nvarchar](500) NOT NULL,
	[file_type] [nvarchar](10) NOT NULL,
	[file_size_bytes] [bigint] NOT NULL,
	[line_count] [int] NOT NULL,
	[last_modified] [datetime] NULL,
	[description] [nvarchar](max) NULL,
	[created_date] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_function_calls]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_function_calls](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[file_id] [int] NOT NULL,
	[function_name] [nvarchar](255) NOT NULL,
	[line_number] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_functions]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_functions](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[file_id] [int] NOT NULL,
	[function_name] [nvarchar](255) NOT NULL,
	[line_number] [int] NOT NULL,
	[end_line_number] [int] NULL,
	[parameters] [nvarchar](max) NULL,
	[class_name] [nvarchar](255) NULL,
	[visibility] [nvarchar](20) NULL,
	[is_static] [bit] NOT NULL,
	[description] [nvarchar](max) NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_scan_runs]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_scan_runs](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[started_at] [datetime] NOT NULL,
	[completed_at] [datetime] NULL,
	[status] [nvarchar](20) NOT NULL,
	[files_scanned] [int] NOT NULL,
	[functions_found] [int] NOT NULL,
	[classes_found] [int] NOT NULL,
	[error_message] [nvarchar](max) NULL,
	[scanned_by] [nvarchar](100) NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[wiki_session_vars]    Script Date: 07/02/2026 16:27:16 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[wiki_session_vars](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[file_id] [int] NOT NULL,
	[variable_name] [nvarchar](255) NOT NULL,
	[access_type] [nvarchar](10) NOT NULL,
	[line_number] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO
ALTER TABLE [dbo].[analyst_teams] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[analysts] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[analysts] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[apikeys] ADD  CONSTRAINT [DF_apikeys_datestamp]  DEFAULT (getdate()) FOR [datestamp]
GO
ALTER TABLE [dbo].[apikeys] ADD  CONSTRAINT [DF_apikeys_active]  DEFAULT ((1)) FOR [active]
GO
ALTER TABLE [dbo].[calendar_categories] ADD  DEFAULT ('#ef6c00') FOR [color]
GO
ALTER TABLE [dbo].[calendar_categories] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[calendar_categories] ADD  DEFAULT (getdate()) FOR [created_at]
GO
ALTER TABLE [dbo].[calendar_categories] ADD  DEFAULT (getdate()) FOR [updated_at]
GO
ALTER TABLE [dbo].[calendar_events] ADD  DEFAULT ((0)) FOR [all_day]
GO
ALTER TABLE [dbo].[calendar_events] ADD  DEFAULT (getdate()) FOR [created_at]
GO
ALTER TABLE [dbo].[calendar_events] ADD  DEFAULT (getdate()) FOR [updated_at]
GO
ALTER TABLE [dbo].[change_attachments] ADD  DEFAULT (getdate()) FOR [uploaded_datetime]
GO
ALTER TABLE [dbo].[changes] ADD  DEFAULT ('Normal') FOR [change_type]
GO
ALTER TABLE [dbo].[changes] ADD  DEFAULT ('Draft') FOR [status]
GO
ALTER TABLE [dbo].[changes] ADD  DEFAULT ('Medium') FOR [priority]
GO
ALTER TABLE [dbo].[changes] ADD  DEFAULT ('Medium') FOR [impact]
GO
ALTER TABLE [dbo].[changes] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[changes] ADD  DEFAULT (getdate()) FOR [modified_datetime]
GO
ALTER TABLE [dbo].[department_teams] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[departments] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[departments] ADD  DEFAULT ((0)) FOR [display_order]
GO
ALTER TABLE [dbo].[departments] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[email_attachments] ADD  DEFAULT ((0)) FOR [is_inline]
GO
ALTER TABLE [dbo].[email_attachments] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[emails] ADD  DEFAULT ((0)) FOR [has_attachments]
GO
ALTER TABLE [dbo].[emails] ADD  DEFAULT ((0)) FOR [is_read]
GO
ALTER TABLE [dbo].[emails] ADD  DEFAULT (getdate()) FOR [processed_datetime]
GO
ALTER TABLE [dbo].[emails] ADD  DEFAULT ((0)) FOR [ticket_created]
GO
ALTER TABLE [dbo].[emails] ADD  DEFAULT ('New') FOR [status]
GO
ALTER TABLE [dbo].[emails] ADD  DEFAULT ((0)) FOR [is_initial]
GO
ALTER TABLE [dbo].[emails] ADD  DEFAULT ('Inbound') FOR [direction]
GO
ALTER TABLE [dbo].[form_fields] ADD  DEFAULT ((0)) FOR [is_required]
GO
ALTER TABLE [dbo].[form_fields] ADD  DEFAULT ((0)) FOR [sort_order]
GO
ALTER TABLE [dbo].[form_submissions] ADD  DEFAULT (getdate()) FOR [submitted_date]
GO
ALTER TABLE [dbo].[forms] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[forms] ADD  DEFAULT (getdate()) FOR [created_date]
GO
ALTER TABLE [dbo].[forms] ADD  DEFAULT (getdate()) FOR [modified_date]
GO
ALTER TABLE [dbo].[knowledge_articles] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[knowledge_articles] ADD  DEFAULT (getdate()) FOR [modified_datetime]
GO
ALTER TABLE [dbo].[knowledge_articles] ADD  DEFAULT ((1)) FOR [is_published]
GO
ALTER TABLE [dbo].[knowledge_articles] ADD  DEFAULT ((0)) FOR [view_count]
GO
ALTER TABLE [dbo].[knowledge_articles] ADD  DEFAULT ((0)) FOR [is_archived]
GO
ALTER TABLE [dbo].[knowledge_tags] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[morningChecks_Checks] ADD  DEFAULT ((1)) FOR [IsActive]
GO
ALTER TABLE [dbo].[morningChecks_Checks] ADD  DEFAULT ((0)) FOR [SortOrder]
GO
ALTER TABLE [dbo].[morningChecks_Checks] ADD  DEFAULT (getdate()) FOR [CreatedDate]
GO
ALTER TABLE [dbo].[morningChecks_Checks] ADD  DEFAULT (getdate()) FOR [ModifiedDate]
GO
ALTER TABLE [dbo].[morningChecks_Results] ADD  DEFAULT (getdate()) FOR [CreatedDate]
GO
ALTER TABLE [dbo].[morningChecks_Results] ADD  DEFAULT (getdate()) FOR [ModifiedDate]
GO
ALTER TABLE [dbo].[software_inventory_apps] ADD  CONSTRAINT [DF_software_inventory_apps_first_detected]  DEFAULT (getdate()) FOR [first_detected]
GO
ALTER TABLE [dbo].[software_inventory_detail] ADD  DEFAULT (getdate()) FOR [created_at]
GO
ALTER TABLE [dbo].[software_inventory_detail] ADD  DEFAULT (getdate()) FOR [last_seen]
GO
ALTER TABLE [dbo].[system_logs] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[system_settings] ADD  DEFAULT (getdate()) FOR [updated_datetime]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ('openid email offline_access Mail.Read Mail.ReadWrite Mail.Send') FOR [oauth_scopes]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ('outlook.office365.com') FOR [imap_server]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ((993)) FOR [imap_port]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ('ssl') FOR [imap_encryption]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ('INBOX') FOR [email_folder]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ((10)) FOR [max_emails_per_check]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ((0)) FOR [mark_as_read]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[target_mailboxes] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[teams] ADD  DEFAULT ((0)) FOR [display_order]
GO
ALTER TABLE [dbo].[teams] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[teams] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[teams] ADD  DEFAULT (getdate()) FOR [updated_datetime]
GO
ALTER TABLE [dbo].[ticket_audit] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[ticket_notes] ADD  DEFAULT ((1)) FOR [is_internal]
GO
ALTER TABLE [dbo].[ticket_notes] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[ticket_origins] ADD  DEFAULT ((0)) FOR [display_order]
GO
ALTER TABLE [dbo].[ticket_origins] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[ticket_origins] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[ticket_prefixes] ADD  DEFAULT ((0)) FOR [is_default]
GO
ALTER TABLE [dbo].[ticket_types] ADD  DEFAULT ((1)) FOR [is_active]
GO
ALTER TABLE [dbo].[ticket_types] ADD  DEFAULT ((0)) FOR [display_order]
GO
ALTER TABLE [dbo].[ticket_types] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[tickets] ADD  DEFAULT ('Open') FOR [status]
GO
ALTER TABLE [dbo].[tickets] ADD  DEFAULT ('Normal') FOR [priority]
GO
ALTER TABLE [dbo].[tickets] ADD  DEFAULT (getdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[tickets] ADD  DEFAULT (getdate()) FOR [updated_datetime]
GO
ALTER TABLE [dbo].[users] ADD  DEFAULT (getdate()) FOR [created_at]
GO
ALTER TABLE [dbo].[users_assets] ADD  DEFAULT (getdate()) FOR [assigned_datetime]
GO
ALTER TABLE [dbo].[asset_history] ADD  DEFAULT (getutcdate()) FOR [created_datetime]
GO
ALTER TABLE [dbo].[wiki_files] ADD  DEFAULT ((0)) FOR [file_size_bytes]
GO
ALTER TABLE [dbo].[wiki_files] ADD  DEFAULT ((0)) FOR [line_count]
GO
ALTER TABLE [dbo].[wiki_files] ADD  DEFAULT (getdate()) FOR [created_date]
GO
ALTER TABLE [dbo].[wiki_functions] ADD  DEFAULT ((0)) FOR [is_static]
GO
ALTER TABLE [dbo].[wiki_scan_runs] ADD  DEFAULT (getdate()) FOR [started_at]
GO
ALTER TABLE [dbo].[wiki_scan_runs] ADD  DEFAULT ('running') FOR [status]
GO
ALTER TABLE [dbo].[wiki_scan_runs] ADD  DEFAULT ((0)) FOR [files_scanned]
GO
ALTER TABLE [dbo].[wiki_scan_runs] ADD  DEFAULT ((0)) FOR [functions_found]
GO
ALTER TABLE [dbo].[wiki_scan_runs] ADD  DEFAULT ((0)) FOR [classes_found]
GO
ALTER TABLE [dbo].[analyst_teams]  WITH CHECK ADD  CONSTRAINT [FK_analyst_teams_analyst] FOREIGN KEY([analyst_id])
REFERENCES [dbo].[analysts] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[analyst_teams] CHECK CONSTRAINT [FK_analyst_teams_analyst]
GO
ALTER TABLE [dbo].[analyst_teams]  WITH CHECK ADD  CONSTRAINT [FK_analyst_teams_team] FOREIGN KEY([team_id])
REFERENCES [dbo].[teams] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[analyst_teams] CHECK CONSTRAINT [FK_analyst_teams_team]
GO
ALTER TABLE [dbo].[calendar_events]  WITH CHECK ADD FOREIGN KEY([category_id])
REFERENCES [dbo].[calendar_categories] ([id])
GO
ALTER TABLE [dbo].[change_attachments]  WITH CHECK ADD  CONSTRAINT [FK_change_attachments_change] FOREIGN KEY([change_id])
REFERENCES [dbo].[changes] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[change_attachments] CHECK CONSTRAINT [FK_change_attachments_change]
GO
ALTER TABLE [dbo].[change_attachments]  WITH CHECK ADD  CONSTRAINT [FK_change_attachments_uploaded_by] FOREIGN KEY([uploaded_by_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[change_attachments] CHECK CONSTRAINT [FK_change_attachments_uploaded_by]
GO
ALTER TABLE [dbo].[changes]  WITH CHECK ADD  CONSTRAINT [FK_changes_approver] FOREIGN KEY([approver_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[changes] CHECK CONSTRAINT [FK_changes_approver]
GO
ALTER TABLE [dbo].[changes]  WITH CHECK ADD  CONSTRAINT [FK_changes_assigned_to] FOREIGN KEY([assigned_to_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[changes] CHECK CONSTRAINT [FK_changes_assigned_to]
GO
ALTER TABLE [dbo].[changes]  WITH CHECK ADD  CONSTRAINT [FK_changes_created_by] FOREIGN KEY([created_by_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[changes] CHECK CONSTRAINT [FK_changes_created_by]
GO
ALTER TABLE [dbo].[changes]  WITH CHECK ADD  CONSTRAINT [FK_changes_requester] FOREIGN KEY([requester_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[changes] CHECK CONSTRAINT [FK_changes_requester]
GO
ALTER TABLE [dbo].[department_teams]  WITH CHECK ADD  CONSTRAINT [FK_department_teams_department] FOREIGN KEY([department_id])
REFERENCES [dbo].[departments] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[department_teams] CHECK CONSTRAINT [FK_department_teams_department]
GO
ALTER TABLE [dbo].[department_teams]  WITH CHECK ADD  CONSTRAINT [FK_department_teams_team] FOREIGN KEY([team_id])
REFERENCES [dbo].[teams] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[department_teams] CHECK CONSTRAINT [FK_department_teams_team]
GO
ALTER TABLE [dbo].[email_attachments]  WITH CHECK ADD  CONSTRAINT [FK_email_attachments_email] FOREIGN KEY([email_id])
REFERENCES [dbo].[emails] ([id])
GO
ALTER TABLE [dbo].[email_attachments] CHECK CONSTRAINT [FK_email_attachments_email]
GO
ALTER TABLE [dbo].[emails]  WITH CHECK ADD  CONSTRAINT [FK_emails_analysts] FOREIGN KEY([assigned_analyst_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[emails] CHECK CONSTRAINT [FK_emails_analysts]
GO
ALTER TABLE [dbo].[emails]  WITH CHECK ADD  CONSTRAINT [FK_emails_departments] FOREIGN KEY([department_id])
REFERENCES [dbo].[departments] ([id])
GO
ALTER TABLE [dbo].[emails] CHECK CONSTRAINT [FK_emails_departments]
GO
ALTER TABLE [dbo].[emails]  WITH CHECK ADD  CONSTRAINT [FK_emails_mailbox] FOREIGN KEY([mailbox_id])
REFERENCES [dbo].[target_mailboxes] ([id])
GO
ALTER TABLE [dbo].[emails] CHECK CONSTRAINT [FK_emails_mailbox]
GO
ALTER TABLE [dbo].[emails]  WITH CHECK ADD  CONSTRAINT [FK_emails_ticket_types] FOREIGN KEY([ticket_type_id])
REFERENCES [dbo].[ticket_types] ([id])
GO
ALTER TABLE [dbo].[emails] CHECK CONSTRAINT [FK_emails_ticket_types]
GO
ALTER TABLE [dbo].[form_fields]  WITH CHECK ADD  CONSTRAINT [FK_form_fields_form] FOREIGN KEY([form_id])
REFERENCES [dbo].[forms] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[form_fields] CHECK CONSTRAINT [FK_form_fields_form]
GO
ALTER TABLE [dbo].[form_submission_data]  WITH CHECK ADD  CONSTRAINT [FK_submission_data_field] FOREIGN KEY([field_id])
REFERENCES [dbo].[form_fields] ([id])
GO
ALTER TABLE [dbo].[form_submission_data] CHECK CONSTRAINT [FK_submission_data_field]
GO
ALTER TABLE [dbo].[form_submission_data]  WITH CHECK ADD  CONSTRAINT [FK_submission_data_submission] FOREIGN KEY([submission_id])
REFERENCES [dbo].[form_submissions] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[form_submission_data] CHECK CONSTRAINT [FK_submission_data_submission]
GO
ALTER TABLE [dbo].[form_submissions]  WITH CHECK ADD  CONSTRAINT [FK_form_submissions_form] FOREIGN KEY([form_id])
REFERENCES [dbo].[forms] ([id])
GO
ALTER TABLE [dbo].[form_submissions] CHECK CONSTRAINT [FK_form_submissions_form]
GO
ALTER TABLE [dbo].[knowledge_article_tags]  WITH CHECK ADD FOREIGN KEY([article_id])
REFERENCES [dbo].[knowledge_articles] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[knowledge_article_tags]  WITH CHECK ADD FOREIGN KEY([tag_id])
REFERENCES [dbo].[knowledge_tags] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[knowledge_articles]  WITH CHECK ADD FOREIGN KEY([author_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[knowledge_articles]  WITH CHECK ADD  CONSTRAINT [FK_knowledge_articles_owner] FOREIGN KEY([owner_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[knowledge_articles] CHECK CONSTRAINT [FK_knowledge_articles_owner]
GO
ALTER TABLE [dbo].[knowledge_articles]  WITH CHECK ADD  CONSTRAINT [FK_knowledge_articles_archived_by] FOREIGN KEY([archived_by_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[knowledge_articles] CHECK CONSTRAINT [FK_knowledge_articles_archived_by]
GO
ALTER TABLE [dbo].[morningChecks_Results]  WITH CHECK ADD  CONSTRAINT [FK_Results_Checks] FOREIGN KEY([CheckID])
REFERENCES [dbo].[morningChecks_Checks] ([CheckID])
GO
ALTER TABLE [dbo].[morningChecks_Results] CHECK CONSTRAINT [FK_Results_Checks]
GO
ALTER TABLE [dbo].[software_inventory_detail]  WITH CHECK ADD  CONSTRAINT [FK_SoftwareDetail_App] FOREIGN KEY([app_id])
REFERENCES [dbo].[software_inventory_apps] ([id])
GO
ALTER TABLE [dbo].[software_inventory_detail] CHECK CONSTRAINT [FK_SoftwareDetail_App]
GO
ALTER TABLE [dbo].[ticket_audit]  WITH CHECK ADD FOREIGN KEY([analyst_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[ticket_audit]  WITH CHECK ADD FOREIGN KEY([ticket_id])
REFERENCES [dbo].[tickets] ([id])
GO
ALTER TABLE [dbo].[ticket_notes]  WITH CHECK ADD  CONSTRAINT [FK_notes_analysts] FOREIGN KEY([analyst_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[ticket_notes] CHECK CONSTRAINT [FK_notes_analysts]
GO
ALTER TABLE [dbo].[ticket_notes]  WITH CHECK ADD  CONSTRAINT [FK_notes_tickets] FOREIGN KEY([ticket_id])
REFERENCES [dbo].[tickets] ([id])
GO
ALTER TABLE [dbo].[ticket_notes] CHECK CONSTRAINT [FK_notes_tickets]
GO
ALTER TABLE [dbo].[ticket_prefixes]  WITH CHECK ADD  CONSTRAINT [FK_prefixes_departments] FOREIGN KEY([department_id])
REFERENCES [dbo].[departments] ([id])
GO
ALTER TABLE [dbo].[ticket_prefixes] CHECK CONSTRAINT [FK_prefixes_departments]
GO
ALTER TABLE [dbo].[tickets]  WITH CHECK ADD  CONSTRAINT [FK_tickets_analysts] FOREIGN KEY([assigned_analyst_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[tickets] CHECK CONSTRAINT [FK_tickets_analysts]
GO
ALTER TABLE [dbo].[tickets]  WITH CHECK ADD  CONSTRAINT [FK_tickets_departments] FOREIGN KEY([department_id])
REFERENCES [dbo].[departments] ([id])
GO
ALTER TABLE [dbo].[tickets] CHECK CONSTRAINT [FK_tickets_departments]
GO
ALTER TABLE [dbo].[tickets]  WITH CHECK ADD  CONSTRAINT [FK_tickets_origin] FOREIGN KEY([origin_id])
REFERENCES [dbo].[ticket_origins] ([id])
GO
ALTER TABLE [dbo].[tickets] CHECK CONSTRAINT [FK_tickets_origin]
GO
ALTER TABLE [dbo].[tickets]  WITH CHECK ADD  CONSTRAINT [FK_tickets_ticket_types] FOREIGN KEY([ticket_type_id])
REFERENCES [dbo].[ticket_types] ([id])
GO
ALTER TABLE [dbo].[tickets] CHECK CONSTRAINT [FK_tickets_ticket_types]
GO
ALTER TABLE [dbo].[tickets]  WITH CHECK ADD  CONSTRAINT [FK_tickets_users] FOREIGN KEY([user_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[tickets] CHECK CONSTRAINT [FK_tickets_users]
GO
ALTER TABLE [dbo].[users_assets]  WITH CHECK ADD  CONSTRAINT [FK_users_assets_analyst] FOREIGN KEY([assigned_by_analyst_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[users_assets] CHECK CONSTRAINT [FK_users_assets_analyst]
GO
ALTER TABLE [dbo].[users_assets]  WITH CHECK ADD  CONSTRAINT [FK_users_assets_user] FOREIGN KEY([user_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[users_assets] CHECK CONSTRAINT [FK_users_assets_user]
GO
ALTER TABLE [dbo].[asset_history]  WITH CHECK ADD FOREIGN KEY([asset_id])
REFERENCES [dbo].[assets] ([id])
GO
ALTER TABLE [dbo].[asset_history]  WITH CHECK ADD FOREIGN KEY([analyst_id])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[wiki_classes]  WITH CHECK ADD  CONSTRAINT [FK_wiki_classes_file] FOREIGN KEY([file_id])
REFERENCES [dbo].[wiki_files] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[wiki_classes] CHECK CONSTRAINT [FK_wiki_classes_file]
GO
ALTER TABLE [dbo].[wiki_db_references]  WITH CHECK ADD  CONSTRAINT [FK_wiki_dbrefs_file] FOREIGN KEY([file_id])
REFERENCES [dbo].[wiki_files] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[wiki_db_references] CHECK CONSTRAINT [FK_wiki_dbrefs_file]
GO
ALTER TABLE [dbo].[wiki_dependencies]  WITH CHECK ADD  CONSTRAINT [FK_wiki_deps_file] FOREIGN KEY([file_id])
REFERENCES [dbo].[wiki_files] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[wiki_dependencies] CHECK CONSTRAINT [FK_wiki_deps_file]
GO
ALTER TABLE [dbo].[wiki_files]  WITH CHECK ADD  CONSTRAINT [FK_wiki_files_scan] FOREIGN KEY([scan_id])
REFERENCES [dbo].[wiki_scan_runs] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[wiki_files] CHECK CONSTRAINT [FK_wiki_files_scan]
GO
ALTER TABLE [dbo].[wiki_function_calls]  WITH CHECK ADD  CONSTRAINT [FK_wiki_funccalls_file] FOREIGN KEY([file_id])
REFERENCES [dbo].[wiki_files] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[wiki_function_calls] CHECK CONSTRAINT [FK_wiki_funccalls_file]
GO
ALTER TABLE [dbo].[wiki_functions]  WITH CHECK ADD  CONSTRAINT [FK_wiki_functions_file] FOREIGN KEY([file_id])
REFERENCES [dbo].[wiki_files] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[wiki_functions] CHECK CONSTRAINT [FK_wiki_functions_file]
GO
ALTER TABLE [dbo].[wiki_session_vars]  WITH CHECK ADD  CONSTRAINT [FK_wiki_sessvars_file] FOREIGN KEY([file_id])
REFERENCES [dbo].[wiki_files] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[wiki_session_vars] CHECK CONSTRAINT [FK_wiki_sessvars_file]
GO
ALTER TABLE [dbo].[morningChecks_Results]  WITH CHECK ADD CHECK  (([Status]='Green' OR [Status]='Amber' OR [Status]='Red'))
GO
n/****** Object:  Table [dbo].[software_licences]    Script Date: 08/02/2026 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[software_licences](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[app_id] [int] NOT NULL,
	[licence_type] [nvarchar](50) NOT NULL,
	[licence_key] [nvarchar](500) NULL,
	[quantity] [int] NULL,
	[renewal_date] [date] NULL,
	[notice_period_days] [int] NULL,
	[portal_url] [nvarchar](500) NULL,
	[cost] [decimal](10, 2) NULL,
	[currency] [nvarchar](10) NULL,
	[purchase_date] [date] NULL,
	[vendor_contact] [nvarchar](500) NULL,
	[notes] [nvarchar](max) NULL,
	[status] [nvarchar](20) NOT NULL,
	[created_by] [int] NULL,
	[created_at] [datetime] NOT NULL,
	[updated_at] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
ALTER TABLE [dbo].[software_licences] ADD  DEFAULT ('GBP') FOR [currency]
GO
ALTER TABLE [dbo].[software_licences] ADD  DEFAULT ('Active') FOR [status]
GO
ALTER TABLE [dbo].[software_licences] ADD  DEFAULT (getdate()) FOR [created_at]
GO
ALTER TABLE [dbo].[software_licences] ADD  DEFAULT (getdate()) FOR [updated_at]
GO
ALTER TABLE [dbo].[software_licences]  WITH CHECK ADD  CONSTRAINT [FK_SoftwareLicences_App] FOREIGN KEY([app_id])
REFERENCES [dbo].[software_inventory_apps] ([id])
GO
ALTER TABLE [dbo].[software_licences] CHECK CONSTRAINT [FK_SoftwareLicences_App]
GO
ALTER TABLE [dbo].[software_licences]  WITH CHECK ADD  CONSTRAINT [FK_SoftwareLicences_Analyst] FOREIGN KEY([created_by])
REFERENCES [dbo].[analysts] ([id])
GO
ALTER TABLE [dbo].[software_licences] CHECK CONSTRAINT [FK_SoftwareLicences_Analyst]
GOnn-- Module access control per analystnCREATE TABLE [dbo].[analyst_modules](n    [id] [int] IDENTITY(1,1) NOT NULL,n    [analyst_id] [int] NOT NULL,n    [module_key] [nvarchar](50) NOT NULL,n    PRIMARY KEY CLUSTERED ([id] ASC),n    FOREIGN KEY ([analyst_id]) REFERENCES [dbo].[analysts]([id]) ON DELETE CASCADE,n    UNIQUE ([analyst_id], [module_key])n);n

-- Module access control per analyst
CREATE TABLE [dbo].[analyst_modules](
    [id] [int] IDENTITY(1,1) NOT NULL,
    [analyst_id] [int] NOT NULL,
    [module_key] [nvarchar](50) NOT NULL,
    PRIMARY KEY CLUSTERED ([id] ASC),
    FOREIGN KEY ([analyst_id]) REFERENCES [dbo].[analysts]([id]) ON DELETE CASCADE,
    UNIQUE ([analyst_id], [module_key])
);

-- MFA columns for analysts table
ALTER TABLE analysts ADD totp_secret NVARCHAR(500) NULL;
ALTER TABLE analysts ADD totp_enabled BIT NOT NULL DEFAULT 0;

-- Asset types lookup table
CREATE TABLE [dbo].[asset_types] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [name] [nvarchar](100) NOT NULL,
    [description] [nvarchar](255) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [display_order] [int] NOT NULL DEFAULT 0,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC),
    UNIQUE NONCLUSTERED ([name] ASC)
);

-- Asset status types lookup table
CREATE TABLE [dbo].[asset_status_types] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [name] [nvarchar](100) NOT NULL,
    [description] [nvarchar](255) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [display_order] [int] NOT NULL DEFAULT 0,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC),
    UNIQUE NONCLUSTERED ([name] ASC)
);

-- Add type and status columns to assets table
ALTER TABLE [dbo].[assets] ADD [asset_type_id] [int] NULL;
ALTER TABLE [dbo].[assets] ADD [asset_status_id] [int] NULL;

GO
CREATE TABLE [dbo].[supplier_types] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [name] [nvarchar](100) NOT NULL,
    [description] [nvarchar](255) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [display_order] [int] NOT NULL DEFAULT 0,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[supplier_statuses] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [name] [nvarchar](100) NOT NULL,
    [description] [nvarchar](255) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [display_order] [int] NOT NULL DEFAULT 0,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[suppliers] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [legal_name] [nvarchar](255) NOT NULL,
    [trading_name] [nvarchar](255) NULL,
    [reg_number] [nvarchar](50) NULL,
    [vat_number] [nvarchar](50) NULL,
    [supplier_type_id] [int] NULL,
    [supplier_status_id] [int] NULL,
    [address_line_1] [nvarchar](255) NULL,
    [address_line_2] [nvarchar](255) NULL,
    [city] [nvarchar](100) NULL,
    [county] [nvarchar](100) NULL,
    [postcode] [nvarchar](20) NULL,
    [country] [nvarchar](100) NULL,
    [questionnaire_date_issued] [date] NULL,
    [questionnaire_date_received] [date] NULL,
    [comments] [nvarchar](max) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[contacts] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [supplier_id] [int] NULL,
    [first_name] [nvarchar](100) NOT NULL,
    [surname] [nvarchar](100) NOT NULL,
    [email] [nvarchar](255) NULL,
    [mobile] [nvarchar](50) NULL,
    [job_title] [nvarchar](100) NULL,
    [direct_dial] [nvarchar](50) NULL,
    [switchboard] [nvarchar](50) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[contract_statuses] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [name] [nvarchar](100) NOT NULL,
    [description] [nvarchar](255) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [display_order] [int] NOT NULL DEFAULT 0,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[payment_schedules] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [name] [nvarchar](100) NOT NULL,
    [description] [nvarchar](255) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [display_order] [int] NOT NULL DEFAULT 0,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[contract_term_tabs] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [name] [nvarchar](100) NOT NULL,
    [description] [nvarchar](255) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [display_order] [int] NOT NULL DEFAULT 0,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[contract_term_values] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [contract_id] [int] NOT NULL,
    [term_tab_id] [int] NOT NULL,
    [content] [nvarchar](max) NULL,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    [updated_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO
CREATE TABLE [dbo].[contracts] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [contract_number] [nvarchar](50) NOT NULL,
    [title] [nvarchar](255) NOT NULL,
    [description] [nvarchar](max) NULL,
    [supplier_id] [int] NULL,
    [contract_owner_id] [int] NULL,
    [contract_status_id] [int] NULL,
    [contract_start] [date] NULL,
    [contract_end] [date] NULL,
    [notice_period_days] [int] NULL,
    [notice_date] [date] NULL,
    [contract_value] [decimal](18,2) NULL,
    [currency] [nvarchar](3) NULL,
    [payment_schedule_id] [int] NULL,
    [cost_centre] [nvarchar](100) NULL,
    [dms_link] [nvarchar](500) NULL,
    [terms_status] [nvarchar](20) NULL,
    [personal_data_transferred] [bit] NULL,
    [dpia_required] [bit] NULL,
    [dpia_completed_date] [date] NULL,
    [dpia_dms_link] [nvarchar](500) NULL,
    [is_active] [bit] NOT NULL DEFAULT 1,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO

-- Security: additional analyst columns
ALTER TABLE analysts ADD trust_device_enabled BIT NOT NULL DEFAULT 0;
ALTER TABLE analysts ADD password_changed_datetime DATETIME NULL;
ALTER TABLE analysts ADD failed_login_count INT NOT NULL DEFAULT 0;
ALTER TABLE analysts ADD locked_until DATETIME NULL;

-- Trusted devices table
CREATE TABLE [dbo].[trusted_devices] (
    [id] [int] IDENTITY(1,1) NOT NULL,
    [analyst_id] [int] NOT NULL,
    [device_token_hash] [nvarchar](255) NOT NULL,
    [user_agent] [nvarchar](500) NULL,
    [ip_address] [nvarchar](45) NULL,
    [created_datetime] [datetime] NULL DEFAULT GETDATE(),
    [expires_datetime] [datetime] NOT NULL,
    PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO

-- Service Status module
CREATE TABLE [dbo].[status_services](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](100) NOT NULL,
	[description] [nvarchar](500) NULL,
	[is_active] [bit] NOT NULL DEFAULT 1,
	[display_order] [int] NOT NULL DEFAULT 0,
	[created_datetime] [datetime] NULL DEFAULT GETUTCDATE(),
PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO

CREATE TABLE [dbo].[status_incidents](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[title] [nvarchar](255) NOT NULL,
	[status] [nvarchar](30) NOT NULL DEFAULT 'Investigating',
	[comment] [nvarchar](max) NULL,
	[created_by_id] [int] NULL,
	[created_datetime] [datetime] NULL DEFAULT GETUTCDATE(),
	[updated_datetime] [datetime] NULL DEFAULT GETUTCDATE(),
	[resolved_datetime] [datetime] NULL,
PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO

CREATE TABLE [dbo].[status_incident_services](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[incident_id] [int] NOT NULL,
	[service_id] [int] NOT NULL,
	[impact_level] [nvarchar](30) NOT NULL DEFAULT 'Operational',
PRIMARY KEY CLUSTERED ([id] ASC) ON [PRIMARY]
) ON [PRIMARY]
GO

-- Default admin account (username: admin, password: freeitsm)
-- IMPORTANT: Change this password after first login!
INSERT INTO [dbo].[analysts] ([username], [password_hash], [full_name], [email], [is_active], [created_datetime]) VALUES (N'admin', N'$2y$12$z9jzs9Sqol4i.ThVE/wwL.EzvbYtZrU0GHpzUJX7UC6ODp5h.q2U2', N'Administrator', N'admin@localhost', 1, GETUTCDATE());
