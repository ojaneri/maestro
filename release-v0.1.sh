#!/bin/bash

# Script to release version 0.1 of Maestro to GitHub

echo "Adding all changes..."
git add .

echo "Committing changes..."
git commit -m "Release version 0.1 - Redesigned interface with Tailwind CSS and integrated functionalities"

echo "Creating tag v0.1..."
git tag v0.1

echo "Pushing to origin master..."
git push origin master

echo "Pushing tag v0.1..."
git push origin v0.1

echo "Release v0.1 completed!"