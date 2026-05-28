"""
JamboApi CMS — Python SDK
Client typé avec cache, retry et pagination.
"""
from setuptools import setup, find_packages

setup(
    name="jamboapi-sdk",
    version="1.0.0",
    description="JamboApi CMS Python SDK",
    author="JamboApi",
    packages=find_packages(),
    python_requires=">=3.9",
    install_requires=["requests>=2.28"],
)
