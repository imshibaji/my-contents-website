---
title: "Self-Hosting n8n & Ollama on a VPS: Production AI Workflows Without Third-Party API Bills"
description: "A complete step-by-step production guide to deploying an autonomous, self-hosted n8n workflow engine connected to local open-source LLMs via Ollama on a cloud VPS."
summary: "Learn how to bypass recurring OpenAI and Anthropic token charges by deploying self-hosted n8n and Ollama on a Hostinger VPS. This guide walks through Linux server configuration, Docker provisioning, and running private AI agents 24/7."
category: "DevOps & AI"
pubDate: 2026-08-28
tags: ["n8n", "VPS", "DevOps", "Ollama", "Docker", "Self Hosting", "Hostinger"]
readingTime: "9 min read"
featuredImage: ""
videoUrl: "https://www.youtube.com/embed/kY6ruLKLnsY"
videoDuration: "PT29M01S"
---

While running AI workflows locally on your laptop is suitable for rapid prototyping, real-world enterprise automations need to run 24/7 on dedicated cloud infrastructure [00:00:50]. Relying on external proprietary APIs—such as OpenAI, Anthropic, or Google Gemini—creates recurring per-token overhead and introduces unpredictable monthly billing [00:01:20].

The ultimate alternative for software architects and startups is self-hosting: combining **n8n** (the open-source workflow automation standard) [00:00:17] with **Ollama** (local and self-hosted open-weight LLMs) [00:01:52] on a budget-friendly **Virtual Private Server (VPS)** [00:01:02].

In this architectural walkthrough, we break down how to provision a production-ready VPS environment, deploy containerized n8n, and run private AI workflows around the clock.

---

## Why Migrate from Local Machine to Cloud VPS?

1. **24/7 Uninterrupted Trigger Execution:** Automated webhooks, customer intake flows, and scheduled CRON scripts continue running without relying on your personal machine staying awake [00:00:55].
2. **Predictable Flat Infrastructure Costs:** Rather than getting billed per token or per API request, you pay a fixed VPS fee, enjoying unlimited LLM inference on your self-managed server [00:01:58].
3. **Data Isolation & Compliance:** Customer inquiries, internal documentation, and operational data remain strictly within your private server instance [00:01:52].

---

## Architectural Stack Overview

* **Cloud Infrastructure:** Hostinger KVM VPS running Ubuntu 22.04 LTS [00:01:02].
* **Automation Orchestration:** n8n deployed via containerized Docker instances [00:00:17].
* **Inference Engine:** Ollama running open-source models (such as Llama 3 or Mistral) [00:01:52].
* **Networking & Security:** Reverse proxy with SSL/TLS encryption for safe webhook ingestion.

---

## Step-by-Step VPS Provisioning & Deployment

### Step 1: Selecting the Right VPS Configuration
When running AI models alongside workflow engines, memory allocation is your main consideration [00:01:58]:
* **Starter / Light Agents (7B quantized models):** At least 4 vCPU cores and 8GB–16GB RAM is recommended to handle both the OS and Ollama model context in memory.
* **Standard Workflows without LLMs:** 2 vCPU cores and 4GB RAM are sufficient.

Pick an OS image based on Ubuntu or Debian for maximum stability and long-term package support [00:02:06].

### Step 2: Server Security & SSH Access
Log into your server instance via your terminal:
```bash
ssh root@YOUR_SERVER_IP

```

Update all system packages and configure basic firewall settings:

```bash
sudo apt update && sudo apt upgrade -y
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

```

### Step 3: Installing Docker & Docker Compose

Containerization is the cleanest method to maintain n8n and its persistent data volumes [00:02:31]:

```bash
# Install official Docker package
curl -fsSL [https://get.docker.com](https://get.docker.com) -o get-docker.sh
sudo sh get-docker.sh

# Verify daemon status
sudo systemctl status docker

```

### Step 4: Installing & Starting Ollama

Install the Ollama binary natively on your Linux host to allow it direct access to CPU/GPU threads:

```bash
curl -fsSL [https://ollama.com/install.sh](https://ollama.com/install.sh) | sh

```

Pull an optimized open-source model:

```bash
ollama run llama3:8b

```

Ensure Ollama binds properly to the local internal network so that containerized n8n can communicate with `http://host.docker.internal:11434` or your private IP address.

### Step 5: Provisioning n8n Container

Launch n8n with local persistent storage:

```bash
docker run -d \
  --name n8n \
  --restart always \
  -p 5678:5678 \
  -v ~/.n8n:/home/node/.n8n \
  docker.n8n.io/n8nio/n8n

```

Access the dashboard via `http://YOUR_SERVER_IP:5678`, register your owner account, and create your workflow canvas.

---

## Wiring Ollama Inside n8n Workflows

Inside your n8n visual builder [00:27:36]:

1. Add a **Chat Trigger** or **Webhook** node.
2. Connect an **AI Agent Node** or **Basic LLM Chain**.
3. Select the **Ollama Model Node** and specify your server host address and loaded model identifier (`llama3:8b`).
4. Attach a **Window Buffer Memory** node to persist conversation state across multi-turn user sessions.

---

## Video Demonstration & Implementation

Watch the complete, detailed 29-minute tutorial where Shibaji Debnath walks through the live Hostinger dashboard setup, server configuration, and practical n8n workflow deployment:

https://www.youtube.com/watch?v=kY6ruLKLnsY

---

## Frequently Asked Questions (FAQ)

**Can I run multiple AI workflows simultaneously on a single VPS?**

Yes. However, if multiple concurrent users invoke Ollama, response queues will scale with your available CPU and memory resources. Adding dedicated SWAP space is recommended to prevent out-of-memory kernel panics.

**How does n8n compare with Zapier or Make on a VPS?**

Unlike SaaS solutions that charge per step execution, self-hosted n8n gives you unlimited workflow executions without escalating cost tiers [00:00:17].

**Do I need a domain name for this setup?**

While you can access the dashboard using raw server IPs, attaching a domain and configuring a free SSL certificate (via Let's Encrypt / Certbot) is recommended for production webhooks and secure API endpoints.